<?php
/**
 * Hublog 增量数据库迁移方法集合 (V66 - V101)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC
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


    /**
     * V90（migrate82）：补齐「二年 / 四年 / 五年老用户」三枚资历徽章。
     *
     * 背景：用户按徽章中文名下载了一整套徽章素材（29 份），其中
     * 二年 / 四年 / 五年老用户这三份在系统里没有对应徽章 ——
     * badges 表只有 user_1y（一年）、user_3y（三年），素材会变成无人使用的死文件。
     * 这里把缺的三档补上，规则沿用既有的 count/{"days":N}（BadgeService 里
     * days 映射到 reg_days = DATEDIFF(NOW(), created_at)，无需改统计代码）。
     *
     * 徽章图标：App 端 V90 起按 badge_key 优先使用 src/static/badges/<key>.svg 专属素材，
     * 数据库里的 icon 只作为「没有素材时的兜底」，故沿用 clock（与一年/三年一致）。
     *
     * 幂等：INSERT IGNORE + uk_badge_key，重复执行无副作用；
     * 后台改过名称/权重的存量库不会被覆盖。
     */
    private static function migrate82(): void
    {
        try {
            // [badge_key, name, desc, category, icon, color, rule_type, threshold, sort_weight, hideable, display]
            $newBadges = [
                ['user_2y', '二年老用户', '注册满2周年，社区资深老圈友', 'activity', 'clock', '#388e3c', 'count', '{"days":730}', 40, 1, 0],
                ['user_4y', '四年老用户', '注册满4周年，与社区长期同行的元老用户', 'activity', 'clock', '#276749', 'count', '{"days":1460}', 48, 1, 0],
                ['user_5y', '五年老用户', '注册满5周年，社区五年功勋用户', 'activity', 'clock', '#1b5e20', 'count', '{"days":1825}', 52, 1, 0],
            ];
            $sql = "INSERT IGNORE INTO `badges`
                    (`badge_key`,`name`,`desc`,`category`,`icon`,`color`,`rule_type`,`threshold`,`sort_weight`,`hideable`,`display`,`status`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,1)";
            $stmt = Database::getInstance()->prepare($sql);
            foreach ($newBadges as $b) {
                $stmt->execute($b);
            }
            self::log('V90：补齐资历徽章 ' . count($newBadges) . ' 枚（二年/四年/五年老用户）');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V90 badge seed] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检（照 v58 惯例）：三枚徽章必须都在且启用
        try {
            $keys = "'user_2y','user_4y','user_5y'";
            $found = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM `badges` WHERE badge_key IN ({$keys}) AND status = 1"
            );
            $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM `badges`");
            error_log("[Hublog Migration V90 selfcheck] activity_badges_found={$found} badges_total={$total}");
            if ($found === 3) {
                self::log("V90 自检通过：二年/四年/五年老用户徽章已就绪，徽章配置共 {$total} 条");
            } else {
                error_log('[Hublog Migration V90 selfcheck] 资历徽章数量不对（期望 3），请检查上方计数');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V90 selfcheck] ' . $e->getMessage());
        }
    }


    /**
     * V96：商城系统 + 积分大转盘 + 自助充值
     * - shop_products（商品：折扣会员 / 圈子周边 / 圈友闲置）/ shop_orders（订单）
     * - wheel_prizes（大转盘奖品）/ wheel_records（抽奖记录）
     * - recharge_orders 已由 V69 建好（recharge_orders），自助充值只补「下单 + 回调入账」胶水
     * - 种子：商城/转盘相关设置项 + 默认大转盘奖品
     */
    private static function migrate83(): void
    {
        $db = Database::getInstance();
        self::log('开始 V96：商城 + 大转盘 + 自助充值');

        $tables = [
            "CREATE TABLE IF NOT EXISTS `shop_products` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `seller_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=平台；>0=圈友闲置发布者',
                `type` varchar(16) NOT NULL DEFAULT 'physical' COMMENT 'vip 折扣会员 / physical 圈子周边 / info 圈友闲置(仅展示)',
                `title` varchar(120) NOT NULL DEFAULT '',
                `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '主图',
                `images` text COMMENT '图集 JSON',
                `content` text COMMENT '详情',
                `price_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '余额售价（元）',
                `price_points` int unsigned NOT NULL DEFAULT 0 COMMENT '积分售价（双轨：与 price_balance 任选其一）',
                `vip_level` tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'type=vip 时生效',
                `vip_days` int unsigned NOT NULL DEFAULT 0 COMMENT 'type=vip 时生效（天）',
                `stock` int NOT NULL DEFAULT 0 COMMENT 'physical 库存；0=不限',
                `sales` int unsigned NOT NULL DEFAULT 0 COMMENT '销量',
                `sort` int NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 1 COMMENT '1 上架 / 0 下架',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_type_status` (`type`,`status`),
                KEY `idx_seller` (`seller_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城商品'",

            "CREATE TABLE IF NOT EXISTS `shop_orders` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `order_no` varchar(32) NOT NULL DEFAULT '',
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `product_id` bigint unsigned NOT NULL DEFAULT 0,
                `qty` int unsigned NOT NULL DEFAULT 1,
                `pay_currency` varchar(8) NOT NULL DEFAULT 'balance' COMMENT 'balance 余额 / points 积分',
                `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '余额支付金额',
                `points` int unsigned NOT NULL DEFAULT 0 COMMENT '积分支付数量',
                `status` varchar(16) NOT NULL DEFAULT 'paid' COMMENT 'paid 已支付 / refunded 已退款 / closed 已关闭',
                `ship_status` varchar(16) NOT NULL DEFAULT 'none' COMMENT 'none 无需发货 / pending 待发货 / shipped 已发货 / completed 已完成',
                `receiver_name` varchar(32) NOT NULL DEFAULT '',
                `receiver_phone` varchar(32) NOT NULL DEFAULT '',
                `receiver_address` varchar(255) NOT NULL DEFAULT '',
                `remark` varchar(255) NOT NULL DEFAULT '',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `paid_at` datetime DEFAULT NULL,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_user` (`user_id`),
                KEY `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城订单'",

            "CREATE TABLE IF NOT EXISTS `wheel_prizes` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(60) NOT NULL DEFAULT '' COMMENT '奖品名',
                `prize_type` varchar(16) NOT NULL DEFAULT 'points' COMMENT 'points 积分 / balance 余额(分) / vip(天) / thanks 谢谢参与',
                `value` int NOT NULL DEFAULT 0 COMMENT 'points=积分值；balance=金额(分)；vip=天数；thanks=0',
                `probability` decimal(6,3) NOT NULL DEFAULT 0.000 COMMENT '中奖概率(%)',
                `total` int NOT NULL DEFAULT 0 COMMENT '0=不限量',
                `used` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 1 COMMENT '1 启用 / 0 停用',
                `sort` int NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_status_sort` (`status`,`sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大转盘奖品'",

            "CREATE TABLE IF NOT EXISTS `wheel_records` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `prize_id` bigint unsigned NOT NULL DEFAULT 0,
                `prize_type` varchar(16) NOT NULL DEFAULT '',
                `value` int NOT NULL DEFAULT 0,
                `free` tinyint NOT NULL DEFAULT 0 COMMENT '1=用免费次数抽的',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大转盘抽奖记录'"
        ];

        foreach ($tables as $sql) {
            try {
                $db->exec($sql);
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V96 table] ' . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // 设置项种子（key 唯一，重复则忽略，不覆盖现有配置）
        $seeds = [
            ['wheel_cost', '20'],
            ['wheel_free_daily', '1'],
            ['mall_switch', '1'],
            ['shop_user_post', '1'],
            ['shop_notice', '']
        ];
        foreach ($seeds as $s) {
            try {
                Database::insert('settings', ['key' => $s[0], 'value' => $s[1]]);
            } catch (\Throwable $e) {
                // 重复键忽略
            }
        }

        // 默认大转盘奖品（仅当为空时）
        $cnt = (int)Database::fetchColumn("SELECT COUNT(*) FROM `wheel_prizes`");
        if ($cnt === 0) {
            $defaults = [
                ['谢谢参与', 'thanks', 0, 30.000, 0],
                ['10 积分', 'points', 10, 30.000, 0],
                ['50 积分', 'points', 50, 15.000, 0],
                ['100 积分', 'points', 100, 10.000, 0],
                ['1 元余额', 'balance', 100, 8.000, 0],
                ['5 元余额', 'balance', 500, 5.000, 0],
                ['会员 7 天', 'vip', 7, 1.500, 0],
                ['会员 30 天', 'vip', 30, 0.500, 0]
            ];
            $sql = "INSERT INTO `wheel_prizes` (`name`,`prize_type`,`value`,`probability`,`total`,`status`,`sort`)
                    VALUES (?,?,?,?,0,1,?)";
            $stmt = $db->prepare($sql);
            foreach ($defaults as $i => $d) {
                $stmt->execute([$d[0], $d[1], $d[2], $d[3], $i]);
            }
        }

        self::log('V96 完成：商城/大转盘表与默认奖品已就绪');
    }


    /**
     * V92（migrate84）：补 posts.declaration 列——修复 V88 迁移函数误删事故。
     *
     * 背景：migrate80 的函数体在 V89（commit 344f2b5）那次提交里被误删，只剩注释块。
     * run() 的升级循环是 method_exists 保护 + 版本号照写，导致全新安装与老站升级
     * 都静默跳过 V88：schema_versions 写到 82，但 posts 表没有 declaration 列，
     * 首页 pinned 接口 SELECT p.declaration 直接 1054 Unknown column 报错
     * （测试站 test.woquan.top 实测复现：版本号 82、35 列、无 declaration）。
     *
     * 本迁移给缺列的站点补上（SHOW COLUMNS 判存，已有列的站点幂等跳过）；
     * migrate80 函数体已同步补回，未来新站点全新安装不再缺列；
     * criticalColumnsExist 也已加入 declaration，再缺会立即暴露而非静默。
     */
    private static function migrate84(): void
    {
        try {
            $pdo = Database::getInstance();
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'declaration'")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `posts` ADD COLUMN `declaration` varchar(20) NOT NULL DEFAULT '' COMMENT '自主声明（ai/marketing/fiction/opinion/repost，空=未声明）'");
                self::log('V91：posts 已补齐 declaration 自主声明列（补 V88 误删事故）');
            } else {
                self::log('V91：posts.declaration 已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V91 posts.declaration backfill] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检（照 v58 惯例）：列必须存在
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'declaration'"
            );
            if ($exists) {
                self::log('V91 自检通过：posts.declaration 就绪');
            } else {
                error_log('[Hublog Migration V91 selfcheck] posts.declaration 仍缺失，请检查上方错误日志');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V91 selfcheck] ' . $e->getMessage());
        }
    }


    /**
     * V97：App 客户端异常收集表 app_crash_logs
     *
     * 背景：App 端（App.vue 全局异常钩子 → utils/track.js reportError/reportApiError）
     * 早已把 error（崩溃/未捕获异常）与 api_error（接口层异常）上报到 POST /api/app/track，
     * 但后端 AppController::track 只走 Logger::info 写进每日文本日志，没有结构化存储，
     * 无法在后台检索、筛选、聚合。本迁移把这两类事件落库，支撑后台「客户端异常」页面。
     *
     * 设计：
     *   - 单表 app_crash_logs，轻量、只增不删（可定期归档/清理，不在迁移里硬删）；
     *   - error 事件：message/stack/context/route；api_error 事件：api_url/api_code/message/route；
     *   - 公共字段 user_id（0=游客）、platform、client、ip、occurred_at（接口收到时间）；
     *   - 索引覆盖：按 event+时间检索、按 route、按 platform、按 user 排查。
     */
    private static function migrate85(): void
    {
        self::log('开始 V97：App 客户端异常收集表 app_crash_logs');
        $sql = "CREATE TABLE IF NOT EXISTS `app_crash_logs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=游客',
            `event` varchar(16) NOT NULL DEFAULT '' COMMENT 'error 崩溃/未捕获异常 / api_error 接口异常',
            `platform` varchar(24) NOT NULL DEFAULT '' COMMENT 'android/ios/h5',
            `client` varchar(16) NOT NULL DEFAULT '' COMMENT 'x-client-type',
            `route` varchar(160) NOT NULL DEFAULT '' COMMENT '出错时所在页面路由',
            `api_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'api_error：记录的接口路径',
            `api_code` varchar(32) NOT NULL DEFAULT '' COMMENT 'api_error：记录的错误码',
            `message` varchar(500) NOT NULL DEFAULT '' COMMENT '错误摘要',
            `stack` mediumtext COMMENT '堆栈/详细（error 事件），最长 16KB',
            `context` varchar(200) NOT NULL DEFAULT '' COMMENT '上下文（error 事件）',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `occurred_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '接口收到时间',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_event_occurred` (`event`,`occurred_at`),
            KEY `idx_route` (`route`),
            KEY `idx_platform` (`platform`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='App 客户端异常收集'";
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V97 app_crash_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
        self::log('V97 完成：app_crash_logs 表已就绪');
    }


    /**
     * V139：问答帖多回答表 question_answers
     *
     * 原 questions 表只有单条 answer 字段（UNIQUE uk_questions_post 决定一帖一条），
     * 任何人回答都是 UPDATE 覆盖上一个人的答案，且 questionAccept 只能把 status 置 1，
     * 根本没有「采纳哪一条」的概念 —— 用户反馈的「采用哪条回答答案的也不对」即源于此。
     * 改为独立回答表：一人一帖一条回答（uk_qa_post_user），作者采纳其中一条（is_accepted）。
     */
    private static function migrate86(): void
    {
        self::log('开始 V139：问答帖多回答表 question_answers');
        $sql = "CREATE TABLE IF NOT EXISTS `question_answers` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `post_id` int unsigned NOT NULL,
            `user_id` int unsigned NOT NULL,
            `content` text NOT NULL COMMENT '回答内容',
            `is_accepted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=被作者采纳',
            `accepted_at` datetime DEFAULT NULL COMMENT '采纳时间',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_qa_post_user` (`post_id`,`user_id`) COMMENT '每人每帖只答一次',
            KEY `idx_qa_post` (`post_id`),
            KEY `idx_qa_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='问答帖回答（多回答，作者采纳其一）'";
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V139 question_answers] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
            return;
        }

        // 旧数据迁移：questions 里已有的单条 answer 灌进新表（幂等，已存在则跳过）
        try {
            $old = Database::fetchAll(
                "SELECT post_id, answer, answered_by, answered_at, status
                 FROM questions
                 WHERE answer IS NOT NULL AND answer <> ''
                   AND answered_by IS NOT NULL AND answered_by > 0"
            ) ?: [];
            $moved = 0;
            foreach ($old as $r) {
                $postId = (int)$r['post_id'];
                $userId = (int)$r['answered_by'];
                $dup = Database::fetchOne(
                    "SELECT id FROM question_answers WHERE post_id = ? AND user_id = ? LIMIT 1",
                    [$postId, $userId]
                );
                if ($dup) {
                    continue;
                }
                $solved = ((int)$r['status'] === 1);
                Database::insert('question_answers', [
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'content' => $r['answer'],
                    'is_accepted' => $solved ? 1 : 0,
                    'accepted_at' => $solved ? ($r['answered_at'] ?: Helper::now()) : null,
                    'created_at' => $r['answered_at'] ?: Helper::now()
                ]);
                $moved++;
            }
            self::log("V139 完成：question_answers 表已就绪，迁移旧回答 {$moved} 条");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V139 旧回答迁移] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V143：会员折扣卡（商品类型 coupon）
     *
     * 商城原本三类商品：vip 折扣会员（买了直接发会员）/ physical 周边 / info 好物。
     * 「折扣卡」是第四类：买了不发会员，只发一张**券**，用户之后在会员中心买会员时
     * 勾选该券即可打折/立减，券核销后作废（一卡一次）。
     *
     * ① shop_products 加 4 列：
     *    discount_mode  percent=按比例打折（value=20 表示减 20%，即 8 折）
     *                   amount =固定金额立减（value=10 表示减 ¥10）
     *    discount_value 折扣力度（上面两种语义共用一列，由 mode 决定怎么解释）
     *    vip_levels     适用档位 "1,2,3"，空串=三档通用
     *    valid_days     购买后有效天数，0=长期有效
     *
     * ② 新表 user_coupons：用户持有的券。shop_orders 只记录「买了什么商品」，
     *    「这张券用没用、什么时候用的」是另一回事，且核销要跟 vip_subscriptions
     *    的订单号对上，所以必须独立成表。
     */
    private static function migrate87(): void
    {
        self::log('开始 V143：会员折扣卡（shop_products 折扣列 + user_coupons 券表）');

        // ① 给 shop_products 补折扣相关列（每列单独判存在性，保证幂等）
        $cols = [
            ['discount_mode',  "ALTER TABLE `shop_products` ADD COLUMN `discount_mode` varchar(8) NOT NULL DEFAULT 'percent' COMMENT 'percent 打折(减x%) / amount 立减(减x元)' AFTER `vip_days`"],
            ['discount_value', "ALTER TABLE `shop_products` ADD COLUMN `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '折扣力度：percent=减免百分比(20即8折) / amount=减免金额(元)' AFTER `discount_mode`"],
            ['vip_levels',     "ALTER TABLE `shop_products` ADD COLUMN `vip_levels` varchar(32) NOT NULL DEFAULT '' COMMENT '适用会员档位 1,2,3；空=全部档位' AFTER `discount_value`"],
            ['valid_days',     "ALTER TABLE `shop_products` ADD COLUMN `valid_days` int NOT NULL DEFAULT 0 COMMENT 'type=coupon：购买后有效天数，0=长期有效' AFTER `vip_levels`"],
        ];
        foreach ($cols as [$col, $sql]) {
            try {
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_products' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    Database::getInstance()->exec($sql);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V143 shop_products.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ② 用户持有的折扣券
        $sql = "CREATE TABLE IF NOT EXISTS `user_coupons` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL,
            `product_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源商品（shop_products）',
            `order_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '来源订单（shop_orders）',
            `title` varchar(120) NOT NULL DEFAULT '' COMMENT '券名（下单时快照，商品改名不影响已发券）',
            `discount_mode` varchar(8) NOT NULL DEFAULT 'percent' COMMENT 'percent 打折 / amount 立减',
            `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '折扣力度',
            `vip_levels` varchar(32) NOT NULL DEFAULT '' COMMENT '适用档位，空=全部',
            `status` varchar(16) NOT NULL DEFAULT 'unused' COMMENT 'unused 未使用 / used 已使用 / expired 已过期',
            `expire_at` datetime DEFAULT NULL COMMENT '过期时间，NULL=长期有效',
            `used_at` datetime DEFAULT NULL COMMENT '核销时间',
            `used_order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '核销在哪笔会员订单上（vip_subscriptions.order_no）',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_uc_user_status` (`user_id`,`status`),
            KEY `idx_uc_order` (`order_id`),
            KEY `idx_uc_expire` (`expire_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员折扣卡（购买商城折扣卡商品后发放，买会员时勾选核销）'";
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V143 user_coupons] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检：缺表/缺列要能被 criticalTablesExist / criticalColumnsExist 抓到并重跑
        $ok = Database::fetchColumn(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_coupons'"
        );
        if (!$ok) {
            error_log('[Hublog Migration V143] user_coupons 表未能建立，请检查数据库权限');
        }
        self::log('V143 完成：shop_products 折扣列与 user_coupons 表已就绪');
    }


    /**
     * V145：AI 辅助调用记录（ai_usage_logs）
     *
     * 背景：AI 辅助（发布页润色/扩写等）自 V145 起从「未授权一刀切关闭」改为
     * 「未授权每人每天 N 次」的限量试用（License::TRIAL_QUOTA['ai']）。
     * 发布/私信的用量天然落在 posts / messages 里，数行即可；AI 调用不产生任何业务数据，
     * 必须有地方记账，否则日配额形同虚设（用户刷新一次又能用）。
     *
     * 设计：只记「成功生成」的调用（AiController::assist 生成成功后写入），
     * 失败/参数不合法/被频率限制挡下的都不计，避免用户为站点的故障买单。
     * 已授权站点不写（License::recordQuotaUsage 内部判断），表只承载试用期的计数。
     */
    private static function migrate88(): void
    {
        self::log('开始 V145：AI 辅助试用配额记账表 ai_usage_logs');

        $sql = "CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int unsigned NOT NULL COMMENT '调用用户',
            `feature` varchar(16) NOT NULL DEFAULT 'ai' COMMENT '配额项（目前仅 ai，留作后续扩展）',
            `action` varchar(24) NOT NULL DEFAULT '' COMMENT 'AI 动作：polish/expand/continue/...',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_aiu_user_created` (`user_id`,`created_at`),
            KEY `idx_aiu_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 辅助调用记录（未授权试用日配额计数）'";
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V145 ai_usage_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检：缺表要能被 criticalTablesExist 抓到并重跑
        $ok = Database::fetchColumn(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage_logs'"
        );
        if (!$ok) {
            error_log('[Hublog Migration V145] ai_usage_logs 表未能建立，请检查数据库权限');
        }
        self::log('V145 完成：ai_usage_logs 表已就绪');
    }


    /**
     * V168（migrate90）：系统通知虚拟账号 system_notice。
     *
     * 背景：徽章/系统类通知的 sender_id 不能为 0（notifications.sender_id 有外键约束），
     * 历史实现借用 ai_assistant，于是用户看到的徽章通知发送人是「AI评论家」。
     * 这里单独建一个不可登录的虚拟账号当作系统身份。
     */
    private static function migrate90(): void
    {
        try {
            $exists = Database::fetchColumn("SELECT id FROM users WHERE username = 'system_notice' LIMIT 1");
            if ($exists) {
                return;
            }
            Database::insert('users', [
                'username'      => 'system_notice',
                'password'      => Auth::hashPassword(bin2hex(random_bytes(32))), // 随机口令，无人可登录
                'email'         => null,
                'nickname'      => Helper::SYSTEM_SENDER_NICKNAME,
                'avatar'        => Helper::SYSTEM_SENDER_AVATAR,
                'bio'           => '系统通知',
                'role'          => 'user',
                'status'        => 1,
                'token_version' => 1,
                'created_at'    => Helper::now(),
                'updated_at'    => Helper::now()
            ]);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V168 system_notice] ' . $e->getMessage());
        }
    }

    /**
     * V146（migrate89）：会话唯一性约束 —— 根治并发双私聊会话 / 双圈子群。
     *
     * 背景（源码审查 P1-1）：
     * - ChatService::getOrCreatePrivateConversation 与 CircleController::ensureCircleGroup
     *   都是「先 SELECT 后 INSERT」：两个用户同时发起私聊（或两人同时进同一个圈子群）时，
     *   两个进程都查不到已有会话 → 各建一个 → 用户被拉进两个群、消息分裂在两个会话里。
     * - 应用层锁（Cache::withLock）只能串行化单机并发，多机部署或锁降级时仍会漏；
     *   数据库唯一索引是唯一跨机器可靠的最终防线，代码侧 1062 回查兜底。
     *
     * 做法：
     * 1) conversations 补 dm_key 列（minUserId_maxUserId，如 "12_345"）
     * 2) 回填存量私聊会话 dm_key（仅两成员会话；成员数≠2 的脏数据留 NULL 不占键）
     * 3) 合并已存在的重复私聊会话 / 重复圈子群（保留 id 最小者，消息/成员/通知并入后删除），
     *    否则唯一索引建不上、迁移报错
     * 4) 建 uk_dm_key(dm_key) 与 uk_circle_group(circle_id) 唯一索引
     *    （MySQL 唯一索引允许多个 NULL，私聊/野群行不受影响）
     * 5) messages 补 (conversation_id, id) 复合索引，供游标分页
     *    WHERE conversation_id = ? AND id < ? ORDER BY id DESC
     *
     * 幂等：全部 information_schema 先行判断 + INSERT IGNORE 合并，重复执行无副作用。
     */
    private static function migrate89(): void
    {
        self::log('开始 V146：会话唯一性约束（dm_key / uk_circle_group）与 messages 游标索引');
        $pdo = Database::getInstance();

        // ---------- 1) conversations 补 dm_key 列 ----------
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `conversations` LIKE 'dm_key'")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `conversations` ADD COLUMN `dm_key` varchar(64) DEFAULT NULL COMMENT '私聊双方用户ID（min_max），唯一约束防并发双会话' AFTER `circle_id`");
                self::log('V146：conversations 已补 dm_key 列');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 add dm_key] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 2) 回填 dm_key（仅两成员私聊） ----------
        try {
            $pdo->exec(
                "UPDATE `conversations` v
                 JOIN (
                     SELECT conversation_id, MIN(user_id) AS a, MAX(user_id) AS b, COUNT(*) AS cnt
                     FROM `conversation_members`
                     GROUP BY conversation_id
                 ) t ON t.conversation_id = v.id
                 SET v.dm_key = CONCAT(t.a, '_', t.b)
                 WHERE v.type = 'private' AND t.cnt = 2 AND (v.dm_key IS NULL OR v.dm_key = '')"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 backfill dm_key] ' . $e->getMessage());
        }

        // ---------- 3a) 合并重复私聊会话（dm_key 相同，保留 id 最小者） ----------
        try {
            $dups = $pdo->query(
                "SELECT dm_key, MIN(id) AS keep_id
                 FROM `conversations`
                 WHERE type = 'private' AND dm_key IS NOT NULL AND dm_key <> ''
                 GROUP BY dm_key HAVING COUNT(*) > 1"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $mergedConvs = 0;
            $movedMsgs = 0;
            $movedMembers = 0;
            $movedNotifs = 0;
            foreach ($dups as $d) {
                $keepId = (int)$d['keep_id'];
                $dupIds = $pdo->prepare(
                    "SELECT id FROM `conversations` WHERE dm_key = ? AND type = 'private' AND id <> ?"
                );
                $dupIds->execute([$d['dm_key'], $keepId]);
                foreach ($dupIds->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $oid = (int)$row['id'];
                    // 消息并入保留会话（防丢历史）
                    $mv = $pdo->prepare("UPDATE `messages` SET conversation_id = ? WHERE conversation_id = ?");
                    $mv->execute([$keepId, $oid]);
                    $movedMsgs += $mv->rowCount();
                    // 成员并入（uk_conv_user + INSERT IGNORE 去重）
                    $mm = $pdo->prepare(
                        "INSERT IGNORE INTO `conversation_members`
                            (conversation_id, user_id, role, last_read_message_id, muted, pinned, joined_at, created_at)
                         SELECT ?, cm.user_id, cm.role, cm.last_read_message_id, cm.muted, cm.pinned, cm.joined_at, cm.created_at
                         FROM `conversation_members` cm WHERE cm.conversation_id = ?"
                    );
                    $mm->execute([$keepId, $oid]);
                    $movedMembers += $mm->rowCount();
                    // 站内通知指向并入
                    $mn = $pdo->prepare("UPDATE `notifications` SET conversation_id = ? WHERE conversation_id = ?");
                    $mn->execute([$keepId, $oid]);
                    $movedNotifs += $mn->rowCount();
                    // 删除重复会话
                    $pdo->prepare("DELETE FROM `conversation_members` WHERE conversation_id = ?")->execute([$oid]);
                    $pdo->prepare("DELETE FROM `conversations` WHERE id = ?")->execute([$oid]);
                    $mergedConvs++;
                }
            }
            if ($mergedConvs > 0) {
                self::log(sprintf(
                    'V146：合并重复私聊会话 %d 个（消息 %d 行、成员 %d 行、通知 %d 行并入保留会话）',
                    $mergedConvs, $movedMsgs, $movedMembers, $movedNotifs
                ));
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 merge dup private] ' . $e->getMessage());
        }

        // ---------- 3b) 合并重复圈子群（circle_id 相同多群，保留 id 最小者） ----------
        try {
            $dups = $pdo->query(
                "SELECT circle_id, MIN(id) AS keep_id
                 FROM `conversations`
                 WHERE type = 'group' AND circle_id IS NOT NULL
                 GROUP BY circle_id HAVING COUNT(*) > 1"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $mergedGroups = 0;
            foreach ($dups as $d) {
                $keepId = (int)$d['keep_id'];
                $dupIds = $pdo->prepare(
                    "SELECT id FROM `conversations` WHERE circle_id = ? AND type = 'group' AND id <> ?"
                );
                $dupIds->execute([(int)$d['circle_id'], $keepId]);
                foreach ($dupIds->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $oid = (int)$row['id'];
                    $mv = $pdo->prepare("UPDATE `messages` SET conversation_id = ? WHERE conversation_id = ?");
                    $mv->execute([$keepId, $oid]);
                    $mm = $pdo->prepare(
                        "INSERT IGNORE INTO `conversation_members`
                            (conversation_id, user_id, role, last_read_message_id, muted, pinned, joined_at, created_at)
                         SELECT ?, cm.user_id, cm.role, cm.last_read_message_id, cm.muted, cm.pinned, cm.joined_at, cm.created_at
                         FROM `conversation_members` cm WHERE cm.conversation_id = ?"
                    );
                    $mm->execute([$keepId, $oid]);
                    $mn = $pdo->prepare("UPDATE `notifications` SET conversation_id = ? WHERE conversation_id = ?");
                    $mn->execute([$keepId, $oid]);
                    $pdo->prepare("DELETE FROM `conversation_members` WHERE conversation_id = ?")->execute([$oid]);
                    $pdo->prepare("DELETE FROM `conversations` WHERE id = ?")->execute([$oid]);
                    $mergedGroups++;
                }
            }
            if ($mergedGroups > 0) {
                self::log(sprintf('V146：合并重复圈子群 %d 个', $mergedGroups));
            }
            // 重算圈子群 member_count（与 conversation_members 实际行数对齐）
            $pdo->exec(
                "UPDATE `conversations` v
                 SET v.member_count = (SELECT COUNT(*) FROM `conversation_members` cm WHERE cm.conversation_id = v.id)
                 WHERE v.type = 'group' AND v.circle_id IS NOT NULL"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 merge dup circle group] ' . $e->getMessage());
        }

        // ---------- 4) 唯一索引 ----------
        $indexes = [
            'uk_dm_key'       => "ALTER TABLE `conversations` ADD UNIQUE KEY `uk_dm_key` (`dm_key`)",
            'uk_circle_group' => "ALTER TABLE `conversations` ADD UNIQUE KEY `uk_circle_group` (`circle_id`)",
        ];
        foreach ($indexes as $name => $ddl) {
            try {
                $exists = (int)$pdo->query(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = " . $pdo->quote($name)
                )->fetchColumn();
                if (!$exists) {
                    $pdo->exec($ddl);
                    self::log("V146：conversations 已建唯一索引 {$name}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V146 add index {$name}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- 5) messages 游标分页索引 (conversation_id, id) ----------
        try {
            $exists = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'idx_conv_id_id'"
            )->fetchColumn();
            if (!$exists) {
                $pdo->exec("ALTER TABLE `messages` ADD KEY `idx_conv_id_id` (`conversation_id`, `id`)");
                self::log('V146：messages 已补游标分页索引 idx_conv_id_id');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 add idx_conv_id_id] ' . $e->getMessage());
        }

        // ---------- 自检 ----------
        try {
            $dupDm = (int)$pdo->query(
                "SELECT COUNT(*) FROM (
                     SELECT dm_key FROM `conversations`
                     WHERE type = 'private' AND dm_key IS NOT NULL AND dm_key <> ''
                     GROUP BY dm_key HAVING COUNT(*) > 1
                 ) x"
            )->fetchColumn();
            $dupCircle = (int)$pdo->query(
                "SELECT COUNT(*) FROM (
                     SELECT circle_id FROM `conversations`
                     WHERE type = 'group' AND circle_id IS NOT NULL
                     GROUP BY circle_id HAVING COUNT(*) > 1
                 ) x"
            )->fetchColumn();
            $idxDm = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = 'uk_dm_key'"
            )->fetchColumn();
            $idxCircle = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND INDEX_NAME = 'uk_circle_group'"
            )->fetchColumn();
            error_log(sprintf(
                '[Hublog Migration V146 selfcheck] dup_dm_key=%d dup_circle_group=%d idx_dm=%d idx_circle=%d',
                $dupDm, $dupCircle, $idxDm, $idxCircle
            ));
            if ($dupDm === 0 && $dupCircle === 0 && $idxDm > 0 && $idxCircle > 0) {
                self::log('V146 完成：私聊/圈子群唯一约束就绪，messages 游标索引就绪');
            } else {
                error_log('[Hublog Migration V146 selfcheck] 存在未达标项，请检查上方计数');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V146 selfcheck] ' . $e->getMessage());
        }
    }

    /**
     * V169（migrate91）：posts.audit_state —— 区分「因审核转待审」与「管理员下架」。
     *
     * 背景：
     * 审核命中后 ContentAudit 插件把 posts.status 置 0，但 status=0 同时还表示
     * 「管理员下架」「定时未发布」，三者挤在同一个字段里无法区分。
     * 后果：为了让作者能查看并修改自己的待审动态（站内信闭环），必须只放行
     * 「审核待审」这一种。若按 status=0 一刀切放行，作者改一下就能把管理员
     * 下架的内容重新送审上架 —— 等于绕过了管理员的决定。
     *
     * audit_state 语义：
     *   0 = 正常（不涉及审核，或从未命中）
     *   1 = 待审核（因命中敏感词 / AI 判定被转待审）
     *   2 = 审核通过（曾命中，用户修改后重审通过）
     *
     * 放行规则（作者本人）：status=1，或 (status=0 且 audit_state=1)。
     * 管理员下架（status=0 且 audit_state=0）仍对作者不可见。
     */
    private static function migrate91(): void
    {
        try {
            $has = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'audit_state'"
            );
            if ($has) {
                return;
            }
            Database::getInstance()->exec(
                "ALTER TABLE `posts` ADD COLUMN `audit_state` tinyint NOT NULL DEFAULT 0
                 COMMENT '审核状态：0正常 1待审核 2审核通过' AFTER `status`"
            );
            self::log('V169 完成：posts.audit_state 已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V169 posts.audit_state] ' . $e->getMessage());
        }

        // ---------- 收尾自检（缺列就 error_log，别让站点带残库静默运行）----------
        try {
            $has = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'audit_state'"
            );
            if (!$has) {
                error_log('[Hublog Migration V169 verify] posts.audit_state 未建出，请检查上方 V169 日志');
            } else {
                self::log('V169 verify：posts.audit_state 已就绪');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V169 verify] ' . $e->getMessage());
        }
    }

    /**
     * V92：资金 / 权益类表补幂等唯一键（DB 层兜底，防止重复入账 / 重复发券）
     *
     * 背景（见《Hublog 源码分析报告 · 最终版》4.3.7）：
     * 支付回调与钱包写入原本只靠应用层的「先查状态再更新」做幂等，
     * 一旦出现并发、进程崩溃后重试、或攻击者重放回调体，
     * 就可能出现「一份充值翻倍到账」「订单重放重复发券」这类错账事故。
     * 应用层检查是「尽力而为」，DB 唯一约束才是「最后一道闸」。
     *
     * 三张表分别处理：
     *  1. wallet_logs     —— 加 biz_key 列 + 唯一索引，作为业务幂等键。
     *     写入方传 NULL 表示「本条流水天然可重复」（如后台手动调账），
     *     MySQL 唯一索引允许多个 NULL，所以不影响现有写入路径。
     *  2. recharge_orders —— trade_no 加唯一索引。渠道交易号全局唯一，
     *     同一笔渠道流水不可能对应两张本站充值单 —— 这是防「伪造回调 + 重复到账」的关键。
     *     注意：trade_no 现有列默认值是 ''，必须先把待支付单的 '' 改成 NULL，
     *     否则多张待支付单会撞唯一索引。
     *  3. user_coupons    —— 加 uk_coupon_order_slot (order_id, slot) 唯一索引。
     *     注意不是 (user_id, order_id)：一张订单可以按 qty 发多张券
     *     （ShopController::buy 里 for 循环逐张发），用 (user_id, order_id)
     *     会把「买 3 张券」直接顶成唯一键冲突。
     *     用 slot（第几张券，从 0 开始）做复合键既能挡住订单重放重复发券，
     *     又不误伤多张场景。slot 列不存在时补列，默认 0。
     *
     * 全部走 information_schema 先判存在性再执行，重复跑幂等。
     */
    private static function migrate92(): void
    {
        // ---------- 1. wallet_logs：加 biz_key 列 + 唯一索引 ----------
        try {
            $hasCol = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_logs' AND COLUMN_NAME = 'biz_key'"
            );
            if (!$hasCol) {
                Database::getInstance()->exec(
                    "ALTER TABLE `wallet_logs`
                     ADD COLUMN `biz_key` varchar(64) DEFAULT NULL
                     COMMENT '业务幂等键：同一业务动作重复写入时靠它挡重；NULL=不参与去重'"
                );
                self::log('V92：wallet_logs.biz_key 已加');
            }

            $hasUk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_logs' AND INDEX_NAME = 'uk_wallet_biz_key'"
            );
            if (!$hasUk) {
                // 加唯一索引前先清掉历史重复的非空 biz_key（理论上不会有，保险起见）
                Database::getInstance()->exec(
                    "DELETE a FROM wallet_logs a
                     INNER JOIN wallet_logs b
                       ON a.biz_key = b.biz_key AND a.biz_key IS NOT NULL AND a.id > b.id"
                );
                Database::getInstance()->exec(
                    "ALTER TABLE `wallet_logs` ADD UNIQUE KEY `uk_wallet_biz_key` (`biz_key`)"
                );
                self::log('V92：wallet_logs.uk_wallet_biz_key 已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V92 wallet_logs biz_key] ' . $e->getMessage());
        }

        // ---------- 2. recharge_orders：trade_no 唯一索引 ----------
        try {
            // trade_no 原定义是 NOT NULL DEFAULT ''，待支付单全是 ''，
            // 多张并存会撞唯一索引。要让唯一索引生效必须先允许 NULL：
            // MySQL 唯一索引允许存在多个 NULL，正好表达「还没有渠道交易号」。
            $nullable = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recharge_orders'
                   AND COLUMN_NAME = 'trade_no' AND IS_NULLABLE = 'YES'"
            );
            if (!$nullable) {
                Database::getInstance()->exec(
                    "ALTER TABLE `recharge_orders`
                     MODIFY COLUMN `trade_no` varchar(64) DEFAULT NULL COMMENT '渠道交易号（待支付为 NULL）'"
                );
                // 再把历史待支付单的 '' 归一成 NULL
                Database::getInstance()->exec(
                    "UPDATE `recharge_orders` SET `trade_no` = NULL WHERE `trade_no` = ''"
                );
                self::log('V92：recharge_orders.trade_no 已改为可空');
            }

            $hasUk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recharge_orders' AND INDEX_NAME = 'uk_trade_no'"
            );
            if (!$hasUk) {
                // 极端情况下历史库里可能有重复 trade_no（人工改过库），
                // 这时唯一索引加不上也不能让整个迁移挂掉 —— 记日志跳过，
                // 由运维人工核对后补。
                $dup = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM (
                        SELECT `trade_no` FROM `recharge_orders`
                        WHERE `trade_no` IS NOT NULL
                        GROUP BY `trade_no` HAVING COUNT(*) > 1
                     ) t"
                );
                if ($dup > 0) {
                    error_log('[Hublog Migration V92 recharge_orders] 存在 ' . $dup . ' 组重复 trade_no，'
                        . '已跳过 uk_trade_no，请人工核对充值流水后重跑');
                } else {
                    Database::getInstance()->exec(
                        "ALTER TABLE `recharge_orders` ADD UNIQUE KEY `uk_trade_no` (`trade_no`)"
                    );
                    self::log('V92：recharge_orders.uk_trade_no 已加');
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V92 recharge_orders uk_trade_no] ' . $e->getMessage());
        }

        // ---------- 3. user_coupons：加 slot 列 + (order_id, slot) 唯一索引 ----------
        try {
            $hasCol = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_coupons' AND COLUMN_NAME = 'slot'"
            );
            if (!$hasCol) {
                Database::getInstance()->exec(
                    "ALTER TABLE `user_coupons`
                     ADD COLUMN `slot` int unsigned NOT NULL DEFAULT 0
                     COMMENT '同一订单内的券序号（0 起），配合唯一键防订单重放重复发券'
                     AFTER `order_id`"
                );
                self::log('V92：user_coupons.slot 已加');
            }

            $hasUk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_coupons' AND INDEX_NAME = 'uk_coupon_order_slot'"
            );
            if (!$hasUk) {
                // 历史券 slot 全是 0，同一订单多张券会撞键 —— 按 id 顺序重新编号。
                // 两个坑都绕开了：
                //  1) 不用 ROW_NUMBER()（MySQL 8.0 才有的窗口函数，本项目支持 5.7）；
                //  2) 不在 UPDATE 的子查询里引用同表（MySQL 1093：不能指定目标表更新），
                //     改用临时表中转。
                Database::getInstance()->exec(
                    "CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_coupon_slot` (
                        `id` bigint unsigned NOT NULL,
                        `slot` int unsigned NOT NULL DEFAULT 0,
                        PRIMARY KEY (`id`)
                     ) ENGINE=InnoDB"
                );
                Database::getInstance()->exec(
                    "DELETE FROM `tmp_coupon_slot`"
                );
                Database::getInstance()->exec(
                    "INSERT INTO `tmp_coupon_slot` (`id`, `slot`)
                     SELECT u.`id`,
                        (SELECT COUNT(*) - 1 FROM `user_coupons` x
                          WHERE x.`order_id` = u.`order_id` AND x.`id` <= u.`id`)
                     FROM `user_coupons` u
                     WHERE u.`order_id` IS NOT NULL AND u.`order_id` <> 0"
                );
                Database::getInstance()->exec(
                    "UPDATE `user_coupons` u
                     JOIN `tmp_coupon_slot` t ON t.`id` = u.`id`
                     SET u.`slot` = t.`slot`"
                );
                // 无来源订单的历史券（order_id = 0）按自身 id 编号，保证不撞键
                Database::getInstance()->exec(
                    "UPDATE `user_coupons` SET `slot` = `id` WHERE `order_id` IS NULL OR `order_id` = 0"
                );
                Database::getInstance()->exec(
                    "DROP TEMPORARY TABLE IF EXISTS `tmp_coupon_slot`"
                );
                Database::getInstance()->exec(
                    "ALTER TABLE `user_coupons` ADD UNIQUE KEY `uk_coupon_order_slot` (`order_id`, `slot`)"
                );
                self::log('V92：user_coupons.uk_coupon_order_slot 已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V92 user_coupons uk_coupon_order_slot] ' . $e->getMessage());
        }

        // ---------- 收尾自检 ----------
        try {
            foreach ([
                ['wallet_logs', 'biz_key', 'uk_wallet_biz_key'],
                ['recharge_orders', 'trade_no', 'uk_trade_no'],
                ['user_coupons', 'slot', 'uk_coupon_order_slot'],
            ] as [$table, $column, $index]) {
                $hasIdx = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$index}'"
                );
                if (!$hasIdx) {
                    error_log("[Hublog Migration V92 verify] {$table}.{$index} 未建出，请检查上方 V92 日志");
                }
            }
            self::log('V92 自检：幂等唯一键检查完成');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V92 verify] ' . $e->getMessage());
        }
    }

    /**
     * V93：修复 posts 全文索引在非 MySQL 数据库上从未建成的问题
     *
     * 背景（见《Hublog 源码分析报告 · 最终版》4.3.9「MySQL 方言锁死」）：
     * V19 建全文索引时写死了 `WITH PARSER ngram`，这是 MySQL 5.7+ 专有语法。
     * MariaDB 既没有 ngram 插件，也不支持 WITH PARSER 语法，
     * 于是 V19 的 ALTER 必然报语法错 → 索引从来没建成。
     *
     * 后果比「索引缺失」更严重：
     *   SearchController 只按「关键词 ≥ 2 字符」决定用 MATCH...AGAINST，
     *   并不检查索引是否真的存在。索引不存在时 MySQL 报 1191
     *   （Can't find FULLTEXT index matching the column list）→ 搜索接口直接 500。
     *   也就是说 MariaDB 站点的搜索功能是「必然报错」，不是「降级」。
     *
     * 本迁移做两件事：
     *   1. 若 idx_posts_fulltext 不存在，按数据库能力分级补建
     *      （ngram → 普通 FULLTEXT → 不建）；
     *   2. 把结果写进 settings.fulltext_enabled，让 SearchController
     *      知道到底能不能用 MATCH...AGAINST，缺索引时改走 LIKE 而不是抛 1191。
     */
    private static function migrate93(): void
    {
        try {
            self::ensurePostsFulltextIndex();
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V93] ' . $e->getMessage());
        }

        // 记录当前是否具备全文索引能力，供 SearchController 判断
        try {
            $hasIndex = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_posts_fulltext'"
            );
            Database::query(
                "INSERT INTO `settings` (`key`, `value`) VALUES ('fulltext_enabled', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$hasIndex > 0 ? '1' : '0']
            );
            self::log('V93：fulltext_enabled = ' . ($hasIndex > 0 ? '1' : '0'));
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V93 settings] ' . $e->getMessage());
        }
    }

    /**
     * V94：新增 jobs 任务表（异步任务队列）
     *
     * 背景（见《Hublog 源码分析报告 · 最终版》4.5.1 / 阶段 2 第 1 条）：
     * 视频压缩走的是「最多 6 次串行 exec()，每次 set_time_limit(300)」，
     * 最坏 1,800 秒（30 分钟）占住一个 PHP-FPM worker，且这段阻塞发生在请求内，
     * 用户上传一个视频要干等几十分钟甚至超时。
     *
     * 项目里已经有两套「响应先返回、耗时任务后跑」的机制：
     *   1. Plugin::defer() + fastcgi_finish_request() —— 同进程内，任务与请求共享生命周期，
     *      进程被回收任务就丢了，且任务仍然占着同一个 worker；
     *   2. 外部 cron 打 /api/cron/scheduled —— 定时粒度，不适合「上传后立刻压缩」这种秒级任务。
     * 两者都不够，所以引入一张 jobs 表做持久化队列：
     *   - 上传请求只负责把视频原样落盘 + 写一条 compress_video 任务，立刻返回；
     *   - 由 cron worker（/api/cron/jobs）拉取任务执行 ffmpeg 压缩，完成后原地替换文件；
     *   - 任务有状态（pending/running/done/failed）、重试次数与错误摘要，失败可查、可重跑。
     *
     * 表设计要点：
     *   - payload 存 JSON（源文件绝对路径、目标路径等），避免为每种任务加列；
     *   - status + run_after 支持「到点再跑」，避免任务堆积时被立即打满；
     *   - attempts / last_error 让失败任务可观测；
     *   - 同一任务类型 + 同一目标文件不允许重复排队（幂等键 uk_job_dedupe）。
     */
    private static function migrate94(): void
    {
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'"
            );
            if (!$exists) {
                Database::getInstance()->exec(
                    "CREATE TABLE IF NOT EXISTS `jobs` (
                        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                        `type` varchar(32) NOT NULL COMMENT '任务类型：compress_video',
                        `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending 待执行 / running 执行中 / done 已完成 / failed 已失败',
                        `payload` text DEFAULT NULL COMMENT '任务参数 JSON',
                        `dedupe_key` varchar(128) NOT NULL DEFAULT '' COMMENT '幂等键：同键不重复排队',
                        `attempts` int unsigned NOT NULL DEFAULT 0 COMMENT '已尝试次数',
                        `last_error` varchar(500) DEFAULT NULL COMMENT '最近一次错误摘要',
                        `run_after` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最早可执行时间',
                        `started_at` datetime DEFAULT NULL,
                        `finished_at` datetime DEFAULT NULL,
                        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uk_job_dedupe` (`dedupe_key`),
                        KEY `idx_jobs_status_runafter` (`status`, `run_after`),
                        KEY `idx_jobs_type` (`type`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异步任务队列'"
                );
                self::log('V94：jobs 任务表已建');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V94 jobs] ' . $e->getMessage());
        }

        // 收尾自检
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'"
            );
            if (!$exists) {
                error_log('[Hublog Migration V94 verify] jobs 表未建出，请检查上方 V94 日志');
            } else {
                self::log('V94 verify：jobs 任务表已就绪');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V94 verify] ' . $e->getMessage());
        }
    }

    // ==================== V95：分类关注 + 话题头图 ====================

    /**
     * V95 —— 分类关注关系表 + 话题头图字段。
     *
     * ① category_follows —— 用户对分类的关注订阅（关注某频道后该频道出新文章自动推送通知）。
     * ② topics.cover       —— 话题详情页头图，可由后台话题管理手动设置，缺省回退到首帖首图。
     */
    private static function migrate95(): void
    {
        self::log('开始 V95：分类关注表 + 话题头图字段');

        // ---------- ① category_follows：分类关注关系表 ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'category_follows'"
            );
            if (!$exists) {
                Database::getInstance()->exec("CREATE TABLE IF NOT EXISTS `category_follows` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '关注者用户ID',
                    `category_id` int unsigned NOT NULL COMMENT '分类ID',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_category` (`user_id`, `category_id`),
                    KEY `idx_category_id` (`category_id`),
                    CONSTRAINT `fk_category_follows_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_category_follows_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分类关注关系表'");
                self::log('V95：category_follows 关注表已建');
            } else {
                self::log('V95：category_follows 关注表已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V95 category_follows] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }

        // ---------- ② topics.cover：话题头图字段（幂等，先判列是否存在）----------
        try {
            $colExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'topics' AND COLUMN_NAME = 'cover'"
            );
            if (!$colExists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `topics` ADD COLUMN `cover` varchar(500) NOT NULL DEFAULT '' COMMENT '话题头图URL（留空则前端回退到首帖首图）' AFTER `description`"
                );
                self::log('V95：topics.cover 字段已加');
            } else {
                self::log('V95：topics.cover 字段已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V95 topics.cover] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }
    }

    // ==================== V96：付费圈子订阅周期可配 + 到期生命周期 ====================

    /**
     * V96 —— 订阅周期可配 + 到期清退/提醒的基础设施。
     *
     * ① circles.pay_days                 —— 单圈订阅天数，0=继承全局 settings.circle_subscribe_days
     * ② circle_subscribes.notified_before_at / notified_expire_at
     *                                    —— 到期前 / 到期当天提醒的幂等标记，续费时重置为 NULL
     * ③ circle_subscribes.idx_cs_status_expire —— 批量扫描到期订阅的普通索引（不进契约）
     * ④ settings 三个运营配置（只插入缺失项，绝不覆盖运营已改的值）
     *
     * 迁移是 best-effort 的：每段独立 try/catch，末尾统一自检；DDL 失败在 debug 下抛出，
     * 生产环境写日志后由全局终态契约兜底回退版本号。
     */
    private static function migrate96(): void
    {
        self::log('开始 V96：付费圈子订阅周期可配 + 到期生命周期');

        // ---------- ① circles.pay_days ----------
        try {
            $colExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND COLUMN_NAME = 'pay_days'"
            );
            if (!$colExists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `circles` ADD COLUMN `pay_days` int unsigned NOT NULL DEFAULT 0 COMMENT '订阅周期天数，0=跟随全局设置' AFTER `pay_amount`"
                );
                self::log('V96：circles.pay_days 字段已加');
            } else {
                self::log('V96：circles.pay_days 字段已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V96 circles.pay_days] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }

        // ---------- ② circle_subscribes 提醒幂等标记 ----------
        try {
            $colExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_subscribes' AND COLUMN_NAME = 'notified_before_at'"
            );
            if (!$colExists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `circle_subscribes` ADD COLUMN `notified_before_at` datetime DEFAULT NULL COMMENT '到期前提醒发送时间（幂等标记）' AFTER `expire_at`"
                );
                self::log('V96：circle_subscribes.notified_before_at 字段已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V96 circle_subscribes.notified_before_at] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }

        try {
            $colExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_subscribes' AND COLUMN_NAME = 'notified_expire_at'"
            );
            if (!$colExists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `circle_subscribes` ADD COLUMN `notified_expire_at` datetime DEFAULT NULL COMMENT '到期当天提醒发送时间（幂等标记）' AFTER `notified_before_at`"
                );
                self::log('V96：circle_subscribes.notified_expire_at 字段已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V96 circle_subscribes.notified_expire_at] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }

        // ---------- ③ 批量扫描索引（普通索引，不进契约）----------
        try {
            $idxExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_subscribes' AND INDEX_NAME = 'idx_cs_status_expire'"
            );
            if (!$idxExists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `circle_subscribes` ADD KEY `idx_cs_status_expire` (`status`, `expire_at`)"
                );
                self::log('V96：circle_subscribes.idx_cs_status_expire 索引已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V96 circle_subscribes.idx_cs_status_expire] ' . $e->getMessage());
            if (APP_DEBUG) throw $e;
        }

        // ---------- ④ 默认配置（只插缺失项；回退重跑不得重置运营配置）----------
        $defaults = [
            'circle_subscribe_days'   => '365',
            'circle_expire_remind_days' => '7',
            'circle_sub_auto_cleanup' => '1',
        ];
        foreach ($defaults as $key => $value) {
            try {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$key]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $key,
                        'value' => $value,
                        'created_at' => self::now(),
                        'updated_at' => self::now(),
                    ]);
                    self::log('V96：settings.' . $key . ' 默认已写入');
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V96 settings.' . $key . '] ' . $e->getMessage());
                if (APP_DEBUG) throw $e;
            }
        }

        // ---------- ⑤ 末尾自检：3 个列 + 3 个 settings 项必须真实存在 ----------
        try {
            $cols = Database::fetchAll(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND ((TABLE_NAME = 'circles' AND COLUMN_NAME = 'pay_days')
                     OR (TABLE_NAME = 'circle_subscribes' AND COLUMN_NAME IN ('notified_before_at', 'notified_expire_at')))"
            );
            $found = [];
            foreach ($cols as $row) {
                $found[strtolower((string)$row['TABLE_NAME']) . '.' . strtolower((string)$row['COLUMN_NAME'])] = true;
            }
            $expectedCols = [
                'circles.pay_days',
                'circle_subscribes.notified_before_at',
                'circle_subscribes.notified_expire_at',
            ];
            $missing = [];
            foreach ($expectedCols as $col) {
                if (!isset($found[$col])) {
                    $missing[] = $col;
                }
            }
            foreach (array_keys($defaults) as $key) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$key]);
                if (!$exists) {
                    $missing[] = 'settings.' . $key;
                }
            }
            if ($missing !== []) {
                error_log('[Hublog Migration V96 verify] 缺失: ' . implode(', ', $missing));
            } else {
                self::log('V96 verify：3 列 + 3 配置项均已就绪');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V96 verify] ' . $e->getMessage());
        }
    }

    // ==================== V97：内容付费墙（单篇买断 / 专栏订阅）====================

    /**
     * V97 —— 内容付费墙基础设施：5 张 content_* 表。
     *
     * ① content_products      内容商品（单篇/专栏定价、试读比例、有效期）
     * ② content_columns       专栏（作者、有效期、条目数）
     * ③ content_column_items  专栏-文章关联（post_type 冗余）
     * ④ content_orders        内容订单（资金流水，可归档）
     * ⑤ content_entitlements  权益/解锁（付费墙唯一权威来源，expire_at NULL=永久）
     *
     * 全部幂等：先 information_schema 判表，再 CREATE TABLE IF NOT EXISTS，
     * 每段独立 try/catch（debug 抛、生产写日志），末尾统一自检。
     * 不改动 posts 任何列 —— 与 posts 只靠 target_id + post_type 冗余关联。
     */
    private static function migrate97(): void
    {
        self::log('开始 V97：内容付费墙五表');

        // DDL 集中定义为字符串字面量，便于扫描器 token_get_all 抓取表名与唯一键名。
        $tables = [
            'content_products' => "CREATE TABLE IF NOT EXISTS `content_products` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `product_type` enum('post','column') NOT NULL,
                `target_id` int unsigned NOT NULL,
                `post_type` varchar(20) NOT NULL DEFAULT 'normal' COMMENT '冗余帖型铁律',
                `price` decimal(10,2) NOT NULL DEFAULT 0.00,
                `preview_ratio` tinyint unsigned NOT NULL DEFAULT 30 COMMENT '试读百分比0-99',
                `valid_days` int unsigned NOT NULL DEFAULT 0 COMMENT '仅column有效，0跟随专栏',
                `status` tinyint NOT NULL DEFAULT 1,
                `sold_count` int unsigned NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_product` (`product_type`,`target_id`),
                KEY `idx_cp_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容付费商品表'",

            'content_columns' => "CREATE TABLE IF NOT EXISTS `content_columns` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `owner_id` int unsigned NOT NULL,
                `title` varchar(150) NOT NULL,
                `summary` varchar(500) NOT NULL DEFAULT '',
                `cover` varchar(500) NOT NULL DEFAULT '',
                `valid_days` int unsigned NOT NULL DEFAULT 365,
                `item_count` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cc_owner` (`owner_id`),
                KEY `idx_cc_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容专栏表'",

            'content_column_items' => "CREATE TABLE IF NOT EXISTS `content_column_items` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `column_id` int unsigned NOT NULL,
                `post_id` int unsigned NOT NULL,
                `post_type` varchar(20) NOT NULL DEFAULT 'normal' COMMENT '冗余帖型铁律',
                `sort_order` smallint NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_column_item` (`column_id`,`post_id`),
                KEY `idx_cci_post` (`post_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='专栏条目表'",

            'content_orders' => "CREATE TABLE IF NOT EXISTS `content_orders` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `order_no` varchar(40) NOT NULL,
                `user_id` int unsigned NOT NULL,
                `product_type` enum('post','column') NOT NULL,
                `target_id` int unsigned NOT NULL,
                `post_type` varchar(20) NOT NULL DEFAULT '' COMMENT '冗余帖型铁律',
                `amount` decimal(10,2) NOT NULL,
                `points_used` int unsigned NOT NULL DEFAULT 0,
                `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `status` enum('pending','paid','closed','refunded') NOT NULL DEFAULT 'paid',
                `trade_no` varchar(64) DEFAULT NULL,
                `paid_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                UNIQUE KEY `uk_trade_no` (`trade_no`),
                KEY `idx_co_user` (`user_id`,`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容付费订单表'",

            'content_entitlements' => "CREATE TABLE IF NOT EXISTS `content_entitlements` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL,
                `product_type` enum('post','column') NOT NULL,
                `target_id` int unsigned NOT NULL,
                `post_type` varchar(20) NOT NULL DEFAULT 'normal' COMMENT '冗余帖型铁律',
                `source` enum('purchase','manual','vip') NOT NULL DEFAULT 'purchase',
                `order_no` varchar(40) NOT NULL DEFAULT '',
                `expire_at` datetime DEFAULT NULL COMMENT 'NULL=永久',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_entitlement` (`user_id`,`product_type`,`target_id`),
                KEY `idx_ce_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容付费权益表'",
        ];

        foreach ($tables as $name => $ddl) {
            try {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$name]
                );
                if (!$exists) {
                    Database::getInstance()->exec($ddl);
                    self::log('V97：' . $name . ' 表已建');
                } else {
                    self::log('V97：' . $name . ' 表已存在，跳过');
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V97 ' . $name . '] ' . $e->getMessage());
                if (APP_DEBUG) throw $e;
            }
        }

        // ---------- 末尾自检：5 表关键列必须真实存在（DDL try/catch 会静默吞错）----------
        $need = [
            'content_products'     => ['preview_ratio', 'post_type'],
            'content_columns'      => ['valid_days'],
            'content_column_items' => ['post_type'],
            'content_orders'       => ['post_type', 'trade_no'],
            'content_entitlements' => ['post_type', 'expire_at'],
        ];
        try {
            $rows = Database::fetchAll(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('content_products','content_columns','content_column_items','content_orders','content_entitlements')"
            );
            $found = [];
            foreach ($rows as $row) {
                $found[strtolower((string)$row['TABLE_NAME']) . '.' . strtolower((string)$row['COLUMN_NAME'])] = true;
            }
            $missing = [];
            foreach ($need as $table => $cols) {
                foreach ($cols as $col) {
                    if (!isset($found[$table . '.' . $col])) {
                        $missing[] = $table . '.' . $col;
                    }
                }
            }
            if ($missing !== []) {
                error_log('[Hublog Migration V97 verify] 缺失: ' . implode(', ', $missing));
            } else {
                self::log('V97 verify：5 表关键列均已就绪');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V97 verify] ' . $e->getMessage());
        }
    }

    // ==================== V98：私信发送幂等键 ====================

    /**
     * V98 —— 私信发送幂等：messages.client_msg_id + uk_msg_client(sender_id, client_msg_id)。
     *
     * 文本 / 图片 / 视频三条发送链路都携带客户端生成的 client_msg_id；
     * ChatService::sendMessage() 先按 sender_id + client_msg_id 回查，命中则直接返回既有消息；
     * 唯一索引兜住并发重试（1062 时同样回查返回），避免网络重试产生重复私信。
     * 存量行 client_msg_id 均为 NULL，MySQL 唯一索引允许多个 NULL，无需回填数据。
     *
     * DDL 集中定义为字符串字面量，便于 scripts/build_migration_contract.php 的扫描器抓取。
     * 每段 DDL 先 information_schema 判重再执行，异常记日志后原样抛出；末尾统一自检，
     * 缺失即 throw —— 绝不静默吞掉 DDL 失败而把版本号推进到最终态。
     */
    private static function migrate98(): void
    {
        self::log('开始 V98：私信发送幂等键 client_msg_id');

        $columnSql = "ALTER TABLE `messages` ADD COLUMN `client_msg_id` VARCHAR(64) DEFAULT NULL COMMENT '客户端幂等键(sender_id+client_msg_id唯一)'";
        $indexSql = "ALTER TABLE `messages` ADD UNIQUE KEY `uk_msg_client` (`sender_id`, `client_msg_id`)";

        // ---------- ① 幂等键列 ----------
        try {
            $hasColumn = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'client_msg_id'"
            );
            if ($hasColumn === 0) {
                Database::getInstance()->exec($columnSql);
                self::log('V98：messages.client_msg_id 列已添加');
            } else {
                self::log('V98：messages.client_msg_id 列已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V98 column] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② 唯一索引 ----------
        try {
            $hasIndex = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'uk_msg_client'"
            );
            if ($hasIndex === 0) {
                Database::getInstance()->exec($indexSql);
                self::log('V98：messages.uk_msg_client 唯一索引已添加');
            } else {
                self::log('V98：messages.uk_msg_client 唯一索引已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V98 index] ' . $e->getMessage());
            throw $e;
        }

        // ---------- 末尾自检：列与索引必须真实存在，缺失直接抛异常 ----------
        try {
            $columnOk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'client_msg_id'"
            );
            $indexOk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'uk_msg_client'"
            );
            $missing = [];
            if ($columnOk === 0) {
                $missing[] = 'messages.client_msg_id';
            }
            if ($indexOk === 0) {
                $missing[] = 'messages.uk_msg_client';
            }
            if ($missing !== []) {
                throw new \RuntimeException('V98 自检失败，缺失: ' . implode(', ', $missing));
            }
            self::log('V98 verify：client_msg_id 列 + uk_msg_client 索引均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V98 verify] ' . $e->getMessage());
            throw $e;
        }
    }

    // ==================== V99：私信软删除语义统一 ====================

    /**
     * V99 —— 软删除终态加固（V52 五列硬自检 + 存量脏数据幂等修复）。
     *
     * V52 已建好五列，本版不再新增业务列：
     *   messages.deleted_at / deleted_by / delete_scope
     *   conversations.deleted_at
     *   conversation_members.deleted_at
     *
     * 结构缺失直接 throw，绝不推进版本；数据修复全部带 WHERE 判重，可重复跑。
     * 本迁移只做列探测与 UPDATE，不执行任何破坏性 DDL 或清表语句。
     * 无法判定的脏行不猜，落 cache/.soft_delete_incomplete 供巡检，避免回退重跑死循环。
     */
    private static function migrate99(): void
    {
        self::log('开始 V99：私信软删除终态加固');

        $markerFile = __DIR__ . '/../../cache/.soft_delete_incomplete';
        $requiredColumns = [
            ['messages', 'deleted_at'],
            ['messages', 'deleted_by'],
            ['messages', 'delete_scope'],
            ['conversations', 'deleted_at'],
            ['conversation_members', 'deleted_at'],
        ];

        // ---------- ① 硬自检：五列必须真实存在，缺失即中止 ----------
        try {
            $missing = [];
            foreach ($requiredColumns as [$table, $column]) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );
                if ($exists === 0) {
                    $missing[] = $table . '.' . $column;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('V99 自检失败，缺失: ' . implode(', ', $missing));
            }
            self::log('V99 verify：五列软删除字段均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V99 verify] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② 幂等数据修复 ----------
        $hasAuditTable = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_deletions'"
        ) > 0;

        // 2.1 self 删除缺 deleted_by：可见性判据 deleted_by <> viewer 遇 NULL 会让所有人都看不见
        $fixedSelfOwner = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM messages WHERE delete_scope = 'self' AND deleted_by IS NULL"
        );
        if ($fixedSelfOwner > 0) {
            Database::query(
                "UPDATE messages SET deleted_by = sender_id
                 WHERE delete_scope = 'self' AND deleted_by IS NULL AND sender_id IS NOT NULL"
            );
            self::log('V99：回填 self 删除的 deleted_by ' . $fixedSelfOwner . ' 行');
        }

        // 2.2 admin / purge 有 scope 却无 deleted_at：取审计表最早删除时间，无审计则退回消息创建时间
        if ($hasAuditTable) {
            $fixedScopeTime = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM messages WHERE deleted_at IS NULL AND delete_scope IN ('admin', 'purge')"
            );
            if ($fixedScopeTime > 0) {
                Database::query(
                    "UPDATE messages m
                     SET m.deleted_at = COALESCE(
                         (SELECT MIN(d.created_at) FROM message_deletions d WHERE d.message_id = m.id),
                         m.created_at
                     )
                     WHERE m.deleted_at IS NULL AND m.delete_scope IN ('admin', 'purge')"
                );
                self::log('V99：回填 admin/purge 删除时间 ' . $fixedScopeTime . ' 行');
            }
        }

        // 2.3 有 deleted_at 却无 scope：按审计表 scope 回填；查不到或不是合法枚举就不猜，计数巡检
        $unresolved = 0;
        $fixedScope = 0;
        if ($hasAuditTable) {
            $rows = Database::fetchAll(
                "SELECT id, deleted_by FROM messages
                 WHERE deleted_at IS NOT NULL AND delete_scope IS NULL
                 ORDER BY id ASC LIMIT 5000"
            );
            foreach ($rows as $row) {
                $audit = Database::fetchOne(
                    "SELECT scope, operator_id FROM message_deletions
                     WHERE message_id = ? ORDER BY id DESC LIMIT 1",
                    [(int)$row['id']]
                );
                $scope = $audit ? (string)($audit['scope'] ?? '') : '';
                if (!in_array($scope, ['self', 'admin', 'purge'], true)) {
                    $unresolved++;
                    continue;
                }
                if ($scope === 'self' && empty($row['deleted_by']) && $audit && !empty($audit['operator_id'])) {
                    Database::query(
                        "UPDATE messages SET delete_scope = ?, deleted_by = ?
                         WHERE id = ? AND deleted_at IS NOT NULL AND delete_scope IS NULL",
                        [$scope, (int)$audit['operator_id'], (int)$row['id']]
                    );
                } else {
                    Database::query(
                        "UPDATE messages SET delete_scope = ?
                         WHERE id = ? AND deleted_at IS NOT NULL AND delete_scope IS NULL",
                        [$scope, (int)$row['id']]
                    );
                }
                $fixedScope++;
            }
            if ($fixedScope > 0) {
                self::log('V99：按审计 scope 回填 ' . $fixedScope . ' 行');
            }
        } else {
            $unresolved = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM messages WHERE deleted_at IS NOT NULL AND delete_scope IS NULL"
            );
        }

        // ---------- ③ 收尾自检：结构异常 throw；残留脏行只落巡检标记 ----------
        try {
            $stillMissing = [];
            foreach ($requiredColumns as [$table, $column]) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );
                if ($exists === 0) {
                    $stillMissing[] = $table . '.' . $column;
                }
            }
            if ($stillMissing !== []) {
                throw new \RuntimeException('V99 收尾自检失败，缺失: ' . implode(', ', $stillMissing));
            }

            $dirty = (int)Database::fetchColumn(
                "SELECT
                    COALESCE(SUM(CASE WHEN delete_scope = 'self' AND deleted_by IS NULL THEN 1 ELSE 0 END), 0)
                  + COALESCE(SUM(CASE WHEN deleted_at IS NULL AND delete_scope IN ('admin', 'purge') THEN 1 ELSE 0 END), 0)
                  + COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL AND delete_scope IS NULL THEN 1 ELSE 0 END), 0)
                 FROM messages"
            );
            // 未判定的行仍留在 dirty 里（不猜、不猜完就跳过），这里只作为标记里的细分计数
            $incomplete = $dirty;
            if ($incomplete > 0) {
                $payload = json_encode([
                    'version'    => 99,
                    'dirty_rows' => $dirty,
                    'unresolved' => $unresolved,
                    'checked_at' => gmdate('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE);
                @file_put_contents($markerFile, $payload, LOCK_EX);
                error_log('[Hublog Migration V99] 软删除数据仍有 ' . $incomplete . ' 行待人工巡检，详见 ' . $markerFile);
            } else {
                if (is_file($markerFile)) {
                    @unlink($markerFile);
                }
                self::log('V99 收尾自检通过：结构完整、无残留脏行');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V99 final verify] ' . $e->getMessage());
            throw $e;
        }
    }

    // ==================== V100：评论审核状态 + AI 配额预扣 ====================

    /**
     * V100 —— 评论审核状态落库 + AI 配额预扣所需的 status 列。
     *
     * 历史事故根因：ContentAudit 对 comment 写 comments.status=0，但 comments 表
     * 从来没有 status 列 —— PDO ERRMODE_EXCEPTION 被 catch 吞掉，违规评论照常展示，
     * 作者通知也永远不会执行。AI 配额的 check-then-act 同样缺少可原子占位的状态列。
     *
     * 本迁移严格照 V98 范式：
     *   1. 每条 DDL 先查 information_schema 判存在性，不存在才执行（幂等）；
     *   2. 每条独立 try/catch，catch 内 error_log 后 **throw**（绝不静默吞 DDL 失败）；
     *   3. 收尾硬自检三列必须真实存在，缺失直接 throw，版本号绝不推进到半残态。
     */
    private static function migrate100(): void
    {
        self::log('开始 V100：评论审核状态 + AI 配额预扣状态列');

        // ---------- ① comments.status ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'status'"
            );
            if ($exists === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `comments` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1正常 0待审/隐藏'"
                );
                self::log('V100：comments.status 列已添加');
            } else {
                self::log('V100：comments.status 列已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V100 comments.status] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② comments.audit_state ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'audit_state'"
            );
            if ($exists === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `comments` ADD COLUMN `audit_state` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0正常 1待审 2通过'"
                );
                self::log('V100：comments.audit_state 列已添加');
            } else {
                self::log('V100：comments.audit_state 列已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V100 comments.audit_state] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ③ ai_usage_logs.status ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage_logs' AND COLUMN_NAME = 'status'"
            );
            if ($exists === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `ai_usage_logs` ADD COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'ok' COMMENT '配额记录状态 ok/reserved'"
                );
                self::log('V100：ai_usage_logs.status 列已添加');
            } else {
                self::log('V100：ai_usage_logs.status 列已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V100 ai_usage_logs.status] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ④ 配额预扣查询的复合索引 ----------
        try {
            $hasIndex = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_usage_logs'
                   AND INDEX_NAME = 'idx_aiu_user_feature_status_created'"
            );
            if ($hasIndex === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `ai_usage_logs`
                     ADD INDEX `idx_aiu_user_feature_status_created` (`user_id`, `feature`, `status`, `created_at`)"
                );
                self::log('V100：ai_usage_logs.idx_aiu_user_feature_status_created 索引已添加');
            } else {
                self::log('V100：ai_usage_logs.idx_aiu_user_feature_status_created 索引已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V100 ai_usage_logs index] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ⑤ 收尾硬自检：三列必须真实存在 ----------
        try {
            $missing = [];
            $requiredColumns = [
                ['comments', 'status'],
                ['comments', 'audit_state'],
                ['ai_usage_logs', 'status'],
            ];
            foreach ($requiredColumns as [$table, $column]) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );
                if ($exists === 0) {
                    $missing[] = $table . '.' . $column;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('V100 自检失败，缺失: ' . implode(', ', $missing));
            }
            self::log('V100 verify：comments.status / comments.audit_state / ai_usage_logs.status 均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V100 verify] ' . $e->getMessage());
            throw $e;
        }
    }

    // ==================== V101：插件状态 / 插件配置 / 通用单页三表 ====================

    /**
     * V101 —— 扩展闭环基础设施三表（批次 0）。
     *
     *   ① plugin_states  ：插件启停 / 安装状态的唯一可信源，替代 disabled_plugins 黑名单；
     *   ② plugin_configs ：插件配置 KV，按 (plugin_name, config_key) 与核心 settings 白名单隔离；
     *   ③ pages          ：通用单页，slug 唯一；三类协议只放 source='seed' 的空正文占位，
     *                      status=0 草稿不覆盖线上内容，回退仍走 agreement_*_text / 硬编码缺省。
     *
     * 严格照 V100 范式：information_schema 先判存在性 → CREATE TABLE IF NOT EXISTS →
     * 失败记日志并 throw（不静默吞 DDL）→ 收尾硬自检三表与两个唯一键，
     * 任一项缺失都不把版本号推进到半残态。
     */
    private static function migrate101(): void
    {
        self::log('开始 V101：插件状态 / 插件配置 / 通用单页三表');
        $db = Database::getInstance();

        // ---------- ① plugin_states：启停 / 安装状态唯一可信源 ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_states'"
            );
            if ($exists === 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS `plugin_states` (
                    `plugin_name`       varchar(64)   NOT NULL COMMENT '插件目录名，^[A-Za-z][A-Za-z0-9]*$',
                    `enabled`           tinyint(1)    NOT NULL DEFAULT 1 COMMENT '1启用 0停用；替代 disabled_plugins 黑名单',
                    `install_status`    varchar(16)   NOT NULL DEFAULT 'installed' COMMENT 'installed|failed|not_installed',
                    `installed_version` varchar(32)   NOT NULL DEFAULT '' COMMENT '最近一次成功安装的清单版本',
                    `last_error`        varchar(512)  NOT NULL DEFAULT '' COMMENT '最近一次生命周期/迁移失败原因',
                    `installed_at`      datetime      DEFAULT NULL,
                    `updated_at`        datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`plugin_name`),
                    KEY `idx_enabled` (`enabled`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件启停与安装状态（唯一可信源）'");
                self::log('V101：plugin_states 表已创建');
            } else {
                self::log('V101：plugin_states 表已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V101 plugin_states] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② plugin_configs：插件配置隔离 ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plugin_configs'"
            );
            if ($exists === 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS `plugin_configs` (
                    `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
                    `plugin_name`  varchar(64)  NOT NULL COMMENT '插件目录名，作用域隔离',
                    `config_key`   varchar(128) NOT NULL COMMENT '插件内局部键，snake_case',
                    `config_value` text         COMMENT '字符串/JSON 由插件自行解释',
                    `updated_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_plugin_config` (`plugin_name`, `config_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件配置（按插件名隔离）'");
                self::log('V101：plugin_configs 表已创建');
            } else {
                self::log('V101：plugin_configs 表已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V101 plugin_configs] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ③ pages：通用单页 ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages'"
            );
            if ($exists === 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS `pages` (
                    `id`              int unsigned NOT NULL AUTO_INCREMENT,
                    `slug`            varchar(64)  NOT NULL COMMENT '唯一标识：about/help/user/privacy/realname',
                    `title`           varchar(128) NOT NULL,
                    `content`         longtext     COMMENT '富文本正文',
                    `status`          tinyint(1)   NOT NULL DEFAULT 0 COMMENT '0草稿 1发布',
                    `template`        varchar(64)  NOT NULL DEFAULT '' COMMENT '预留模板标识（B2-5）',
                    `seo_title`       varchar(160) NOT NULL DEFAULT '',
                    `seo_description` varchar(300) NOT NULL DEFAULT '',
                    `seo_keywords`    varchar(200) NOT NULL DEFAULT '',
                    `sort_order`      int          NOT NULL DEFAULT 0,
                    `source`          varchar(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual|seed（种子导入标记）',
                    `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_page_slug` (`slug`),
                    KEY `idx_page_status_sort` (`status`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用单页'");
                self::log('V101：pages 表已创建');
            } else {
                self::log('V101：pages 表已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V101 pages] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ④ 半残库列自愈：表已存在但新列缺失时逐列补齐 ----------
        // 这 8 个列名同时也是迁移终态契约 REQUIRED_COLUMN_NAMES 的新增哨兵
        // （自动段由 MigrationContractScanner 从 ADD COLUMN 字面量提取）。
        $columnFixes = [
            ['plugin_states', 'plugin_name', "ALTER TABLE `plugin_states` ADD COLUMN `plugin_name` varchar(64) NOT NULL DEFAULT '' COMMENT '插件目录名'"],
            ['plugin_states', 'install_status', "ALTER TABLE `plugin_states` ADD COLUMN `install_status` varchar(16) NOT NULL DEFAULT 'installed' COMMENT 'installed|failed|not_installed'"],
            ['plugin_states', 'installed_version', "ALTER TABLE `plugin_states` ADD COLUMN `installed_version` varchar(32) NOT NULL DEFAULT '' COMMENT '最近一次成功安装版本'"],
            ['plugin_states', 'last_error', "ALTER TABLE `plugin_states` ADD COLUMN `last_error` varchar(512) NOT NULL DEFAULT '' COMMENT '最近一次失败原因'"],
            ['plugin_configs', 'plugin_name', "ALTER TABLE `plugin_configs` ADD COLUMN `plugin_name` varchar(64) NOT NULL DEFAULT '' COMMENT '插件目录名'"],
            ['plugin_configs', 'config_key', "ALTER TABLE `plugin_configs` ADD COLUMN `config_key` varchar(128) NOT NULL DEFAULT '' COMMENT '插件内局部键'"],
            ['plugin_configs', 'config_value', "ALTER TABLE `plugin_configs` ADD COLUMN `config_value` text COMMENT '配置值'"],
            ['pages', 'slug', "ALTER TABLE `pages` ADD COLUMN `slug` varchar(64) NOT NULL DEFAULT '' COMMENT '单页唯一标识'"],
            ['pages', 'source', "ALTER TABLE `pages` ADD COLUMN `source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual|seed'"],
        ];
        foreach ($columnFixes as [$table, $column, $ddl]) {
            try {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );
                if ($exists === 0) {
                    $db->exec($ddl);
                    self::log("V101：{$table}.{$column} 列已补齐");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V101 {$table}.{$column}] " . $e->getMessage());
                throw $e;
            }
        }

        // ---------- ⑤ 协议占位种子：只写 pages.source='seed'，正文留空 ----------
        // 不写 settings.agreement_*_text：旧配置与控制器硬编码缺省仍是回退链的下一级，
        // 种子仅占位（status=0 草稿），站长确认后再发布，避免升级覆盖线上协议正文。
        try {
            $db->exec("INSERT IGNORE INTO `pages` (`slug`, `title`, `content`, `status`, `source`) VALUES
                ('agreement_user', '用户协议', '', 0, 'seed'),
                ('agreement_privacy', '隐私政策', '', 0, 'seed'),
                ('agreement_realname', '实名认证协议', '', 0, 'seed')");
            self::log('V101：pages 三类协议占位种子已写入（source=seed, status=0）');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V101 seed pages] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ⑥ 收尾硬自检：三表 + 两个唯一键 ----------
        try {
            $missing = [];
            foreach (['plugin_states', 'plugin_configs', 'pages'] as $table) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                if ($exists === 0) {
                    $missing[] = 'table:' . $table;
                }
            }
            $indexes = [
                ['plugin_configs', 'uk_plugin_config'],
                ['pages', 'uk_page_slug'],
            ];
            foreach ($indexes as [$table, $index]) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0",
                    [$table, $index]
                );
                if ($exists === 0) {
                    $missing[] = 'index:' . $table . '.' . $index;
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('V101 自检失败，缺失: ' . implode(', ', $missing));
            }
            self::log('V101 verify：三表与 uk_plugin_config / uk_page_slug 均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V101 verify] ' . $e->getMessage());
            throw $e;
        }

        self::log('V101 完成');
    }
}
