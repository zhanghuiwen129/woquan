<?php
/**
 * Hublog 增量数据库迁移方法集合 (V90 - V97)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC4` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC4
{


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
}
