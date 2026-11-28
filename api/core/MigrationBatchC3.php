<?php
/**
 * Hublog 增量数据库迁移方法集合 (V82 - V89)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC3` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC3
{


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
}
