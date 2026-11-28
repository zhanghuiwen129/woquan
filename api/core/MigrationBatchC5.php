<?php
/**
 * Hublog 增量数据库迁移方法集合 (V98 - V105)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC5` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC5
{

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

    /**
     * V102 —— 会员系统数据一致性治理（阶段3）。
     *
     * 三处 DDL：
     *   ① users.account_state：拆分 status=0 的二义性（封禁/注销/后台软删）。
     *      沿用 posts.audit_state 先例——加辅助列，不改 status 取值，所有既有查询零改动。
     *      0=正常 1=管理员封禁 2=用户注销 3=后台软删。
     *   ② phone_codes.code varchar(10)→varchar(255)：验证码改 bcrypt 哈希存储需要更长列。
     *   ③ points_logs.biz_key + 唯一索引：补齐积分流水的幂等键（对齐 wallet_logs V92）。
     *
     * 全部带信息_schema 探测幂等；末尾自检，缺项抛异常（不静默吞 DDL）。
     */
    private static function migrate102(): void
    {
        $db = Database::getInstance();
        self::log('开始 V102：会员数据一致性（account_state / 验证码哈希列 / 积分幂等键）');

        // ---------- ① users.account_state：加列 + 回填 ----------
        try {
            $hasCol = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_state'"
            );
            if (!$hasCol) {
                $db->exec(
                    "ALTER TABLE `users` ADD COLUMN `account_state` tinyint(1) NOT NULL DEFAULT 0
                     COMMENT '账号状态细分：0正常 1管理员封禁 2用户注销 3后台软删；status=0 的二义性拆分'"
                );
                self::log('V102：users.account_state 已加');
                // 回填：deleted_at 非空 → 2（注销/软删统一标记，二者靠 email 等副作用区分）；
                //       其余 status=0 → 1（封禁）；status=1 保持默认 0。
                $db->exec(
                    "UPDATE users SET account_state = 2 WHERE deleted_at IS NOT NULL AND status = 0"
                );
                $db->exec(
                    "UPDATE users SET account_state = 1 WHERE status = 0 AND deleted_at IS NULL"
                );
                self::log('V102：account_state 存量回填完成');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V102 account_state] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② phone_codes.code 扩列宽 ----------
        try {
            $colLen = (int)Database::fetchColumn(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phone_codes' AND COLUMN_NAME = 'code'"
            );
            if ($colLen > 0 && $colLen < 255) {
                $db->exec("ALTER TABLE `phone_codes` MODIFY COLUMN `code` varchar(255) NOT NULL COMMENT '验证码（bcrypt 哈希；存量明文兼容至 TTL 到期）'");
                self::log('V102：phone_codes.code 已扩到 varchar(255)');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V102 phone_codes.code] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ③ points_logs.biz_key + 唯一索引 ----------
        try {
            $hasCol = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_logs' AND COLUMN_NAME = 'biz_key'"
            );
            if (!$hasCol) {
                $db->exec(
                    "ALTER TABLE `points_logs` ADD COLUMN `biz_key` varchar(64) DEFAULT NULL
                     COMMENT '业务幂等键：同一业务动作重复写入挡重；NULL=不参与去重'"
                );
                self::log('V102：points_logs.biz_key 已加');
            }
            $hasUk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_logs' AND INDEX_NAME = 'uk_points_biz_key'"
            );
            if (!$hasUk) {
                // 清历史重复的非空 biz_key（存量都是 NULL，理论无重复，保险起见）
                $db->exec(
                    "DELETE a FROM points_logs a
                     INNER JOIN points_logs b
                       ON a.biz_key = b.biz_key AND a.biz_key IS NOT NULL AND a.id > b.id"
                );
                $db->exec("ALTER TABLE `points_logs` ADD UNIQUE KEY `uk_points_biz_key` (`biz_key`)");
                self::log('V102：points_logs.uk_points_biz_key 已加');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V102 points_logs biz_key] ' . $e->getMessage());
            throw $e;
        }

        // ---------- 末尾自检：缺任何一项都抛异常，绝不静默推进版本号 ----------
        try {
            $missing = [];
            $checks = [
                ['COLUMNS', 'users', 'account_state'],
                ['COLUMNS', 'phone_codes', 'code'],
                ['COLUMNS', 'points_logs', 'biz_key'],
            ];
            foreach ($checks as [$kind, $table, $name]) {
                $exists = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $name]
                );
                if ($exists === 0) {
                    $missing[] = 'column:' . $table . '.' . $name;
                }
            }
            $uk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_logs' AND INDEX_NAME = 'uk_points_biz_key'"
            );
            if ($uk === 0) {
                $missing[] = 'index:points_logs.uk_points_biz_key';
            }
            // 验证码列宽必须 >= 255（bcrypt 60 字符也要留足余量）
            $codeLen = (int)Database::fetchColumn(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phone_codes' AND COLUMN_NAME = 'code'"
            );
            if ($codeLen < 255) {
                $missing[] = 'column-width:phone_codes.code<' . $codeLen;
            }
            if ($missing !== []) {
                throw new \RuntimeException('V102 自检失败，缺失: ' . implode(', ', $missing));
            }
            self::log('V102 verify：account_state / code(255) / biz_key+uk 均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V102 verify] ' . $e->getMessage());
            throw $e;
        }

        self::log('V102 完成');
    }

    /**
     * V103 —— 会员变更审计表（阶段4低风险增量）。
     *
     * 只记关键操作证据链（VIP 开通/降级、后台积分/余额调整、角色变更），
     * 带 before/after/操作人。不碰 Auth 热路径，不改任何既有表。
     * 写入由 MemberChangeAudit::record() 统一收口，容错不影响主流程。
     */
    private static function migrate103(): void
    {
        self::log('开始 V103：会员变更审计表');
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_change_logs'"
            );
            if ($exists === 0) {
                Database::getInstance()->exec("CREATE TABLE IF NOT EXISTS `member_change_logs` (
                    `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
                    `user_id`       int unsigned NOT NULL COMMENT '目标用户ID',
                    `change_type`   varchar(32)  NOT NULL COMMENT 'vip_grant/vip_expire/vip_downgrade/points_adjust/balance_adjust/role_change',
                    `operator_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '操作人ID，0=系统/cron',
                    `operator_name` varchar(50)  NOT NULL DEFAULT '' COMMENT '操作人名称快照',
                    `before_value`  varchar(255) DEFAULT NULL COMMENT '变更前值',
                    `after_value`   varchar(255) DEFAULT NULL COMMENT '变更后值',
                    `delta`         varchar(32)  NOT NULL DEFAULT '' COMMENT '数值变化量（非数值变更留空）',
                    `biz_type`      varchar(32)  NOT NULL DEFAULT '' COMMENT 'vip/wallet/points/admin',
                    `biz_id`        varchar(64)  NOT NULL DEFAULT '' COMMENT '关联业务ID/订单号',
                    `remark`        varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
                    `ip`            varchar(45)  NOT NULL DEFAULT '' COMMENT '来源IP',
                    `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_created` (`user_id`,`created_at`),
                    KEY `idx_type_created` (`change_type`,`created_at`),
                    KEY `idx_operator` (`operator_id`,`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员变更审计日志'");
                self::log('V103：member_change_logs 表已创建');
            } else {
                self::log('V103：member_change_logs 已存在，跳过');
            }
            // 末尾自检：表必须真实存在
            $check = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_change_logs'"
            );
            if ($check === 0) {
                throw new \RuntimeException('V103 自检失败：member_change_logs 未创建');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V103] ' . $e->getMessage());
            throw $e;
        }
        self::log('V103 完成');
    }

    /**
     * V104 —— 补齐 notifications.conversation_id 与 idx_user_type_read。
     *
     * 背景：这两项由 migrate35（BatchA）添加，但两条全新安装路径的补跑循环都是
     * `for ($v = 36; ...)`，跳过了 migrate35；终态 DDL 基线也没带这两项，导致
     * 新装库私信通知写 conversation_id 抛 1054 被吞 → 私信通知静默丢失。
     * 本地实测升级库（V103）同样缺列，故此处补一次，幂等兼容所有路径。
     *
     * 列与索引分别探测，避免 migrate35 那种「列在但索引缺就不补」的半残状态。
     * 末尾自检缺失时抛异常，绝不让 DDL 失败被吞后继续推进版本号。
     */
    private static function migrate104(): void
    {
        self::log('开始 V104：补齐 notifications.conversation_id 与 idx_user_type_read');
        try {
            $colExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                   AND COLUMN_NAME = 'conversation_id'"
            );
            if ($colExists === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `notifications` ADD COLUMN `conversation_id` bigint unsigned DEFAULT NULL COMMENT '关联会话ID（私信通知）' AFTER `comment_id`"
                );
                self::log('V104：notifications.conversation_id 已添加');
            } else {
                self::log('V104：notifications.conversation_id 已存在，跳过');
            }

            $idxExists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                   AND INDEX_NAME = 'idx_user_type_read'"
            );
            if ($idxExists === 0) {
                Database::getInstance()->exec(
                    "ALTER TABLE `notifications` ADD INDEX `idx_user_type_read` (`user_id`, `type`, `is_read`, `created_at`)"
                );
                self::log('V104：idx_user_type_read 已添加');
            } else {
                self::log('V104：idx_user_type_read 已存在，跳过');
            }

            // 末尾自检：列与索引必须真实存在，否则抛异常中断版本推进
            $checkCol = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                   AND COLUMN_NAME = 'conversation_id'"
            );
            $checkIdx = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
                   AND INDEX_NAME = 'idx_user_type_read'"
            );
            if ($checkCol === 0 || $checkIdx === 0) {
                throw new \RuntimeException(
                    'V104 自检失败：notifications.conversation_id 或 idx_user_type_read 未就位'
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V104] ' . $e->getMessage());
            throw $e;
        }
        self::log('V104 完成');
    }

    // ==================== V105：jobs 优先级 + 死信状态 ====================

    /**
     * V105 —— jobs 队列优先级排序 + 死信状态落地。
     *
     * 死信统一用 status='dead_letter' 表达，不新增 dead_letter 布尔列：
     * 与 pending/running/done/failed 共用一套状态机，stats() 天然多一个计数，
     * purge() 也自动保留。本迁移只新增 priority 一列 + 一条优先级拉取索引。
     *
     * 严格照 V100/V101 范式：information_schema 先判存在性 → DDL 失败记日志并 throw
     * → 收尾硬自检列与索引，缺失绝不把版本号推进到半残态。
     */
    private static function migrate105(): void
    {
        self::log('开始 V105：jobs 优先级 + 死信状态');
        $db = Database::getInstance();

        // ---------- ① jobs 表必须存在（建表由 V94 负责） ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'"
            );
            if ($exists === 0) {
                throw new \RuntimeException('V105：jobs 表不存在，请先修复 V94 迁移');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V105 jobs table] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ② priority 列（幂等） ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'priority'"
            );
            if ($exists === 0) {
                $db->exec(
                    "ALTER TABLE `jobs` ADD COLUMN `priority` int NOT NULL DEFAULT 0 COMMENT '任务优先级，数值越大越先执行'"
                );
                self::log('V105：jobs.priority 列已添加');
            } else {
                self::log('V105：jobs.priority 列已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V105 jobs.priority] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ③ 优先级拉取索引（幂等） ----------
        try {
            $hasIndex = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'
                   AND INDEX_NAME = 'idx_jobs_priority'"
            );
            if ($hasIndex === 0) {
                $db->exec(
                    "ALTER TABLE `jobs` ADD INDEX `idx_jobs_priority` (`status`, `priority`, `run_after`)"
                );
                self::log('V105：jobs.idx_jobs_priority 索引已添加');
            } else {
                self::log('V105：jobs.idx_jobs_priority 索引已存在，跳过');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V105 jobs index] ' . $e->getMessage());
            throw $e;
        }

        // ---------- ④ 收尾硬自检：列 + 索引必须真实存在 ----------
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'priority'"
            );
            $hasIndex = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND INDEX_NAME = 'idx_jobs_priority'"
            );
            if ($exists === 0 || $hasIndex === 0) {
                throw new \RuntimeException(
                    'V105 自检失败，缺失: '
                    . ($exists === 0 ? 'jobs.priority ' : '')
                    . ($hasIndex === 0 ? 'jobs.idx_jobs_priority' : '')
                );
            }
            self::log('V105 verify：jobs.priority / idx_jobs_priority 均已就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V105 verify] ' . $e->getMessage());
            throw $e;
        }

        self::log('V105 完成');
    }
}
