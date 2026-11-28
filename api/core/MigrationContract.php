<?php
/**
 * 迁移终态契约 —— 由 scripts/build_migration_contract.php 生成，勿手工编辑自动段。
 *
 * 单一数据源：迁移源码里所有 CREATE TABLE / ADD COLUMN 的期望终态。
 * 新增迁移后必须重新执行生成器，否则 MigrationContractTest 与 CI 会失败。
 *
 * 用途：
 *   1. Migration::schemaDiff() 用它做「装完 / 升完」的终态自检，漏登的表列不再可能静默通过；
 *   2. 每个 migrateNN 内部 223 个吞异常 catch 因此有了统一兜底 ——
 *      只要终态契约没满足，就抛异常并回退版本号，绝不把半残库标记成成功。
 *
 * 为什么按「表名 + 列名」而不是「表.列」精确对：
 *   同一 migrateNN 里常先改 A 表再批量改 B 表，静态推断列归属会把
 *   circles.announcement 挂到 posts、posts.link_url 挂到 comments（实测误判）。
 *   按列名校验零误报，且足以拦住「DDL 被 catch 吞掉导致整列没建出来」；
 *   高价值列另有 CRITICAL_COLUMNS 做精确「表.列」哨兵。
 *
 * 幂等唯一键是独立的第四个维度：表 / 列都在，但唯一键没建出来的情况
 * （migrate92 的 DDL 被 catch 吞掉）用前三个维度查不出来，而它直接决定
 * 重复支付回调会不会重复入账。见 REQUIRED_UNIQUE_INDEXES。
 *
 * 生成依据：86 张表 / 91 个列名
 * 已排除被 DROP 的表：checkins
 */

declare(strict_types=1);

namespace Hublog\Core;

final class MigrationContract
{
    /** 故意跳号、没有对应 migrateNN 方法的版本（run() 允许推进但不执行 DDL） */
    public const INTENTIONAL_GAPS = [60, 64];

    /** @var list<string> 期望存在的全部表（自动生成） */
    public const REQUIRED_TABLES = [
        'activities',
        'activity_registrations',
        'admin_audit_logs',
        'ai_usage_logs',
        'app_crash_logs',
        'app_versions',
        'appeals',
        'badge_logs',
        'badges',
        'blocked_users',
        'call_records',
        'categories',
        'category_follows',
        'checkin_logs',
        'checkin_posts',
        'circle_join_requests',
        'circle_members',
        'circle_subscribes',
        'circles',
        'comment_likes',
        'comment_marks',
        'comments',
        'content_column_items',
        'content_columns',
        'content_entitlements',
        'content_filter_logs',
        'content_orders',
        'content_products',
        'conversation_members',
        'conversations',
        'drafts',
        'email_verifications',
        'favorites',
        'featured_posts',
        'feed_feedback',
        'file_hashes',
        'follows',
        'friend_links',
        'jobs',
        'licenses',
        'likes',
        'member_change_logs',
        'mentions',
        'message_deletions',
        'messages',
        'notification_prefs',
        'notifications',
        'oauth_accounts',
        'pages',
        'phone_codes',
        'plugin_configs',
        'plugin_states',
        'point_tasks',
        'points_logs',
        'poll_options',
        'poll_votes',
        'polls',
        'post_tags',
        'post_view_logs',
        'posts',
        'push_tokens',
        'question_answers',
        'questions',
        'realname_records',
        'recharge_orders',
        'refresh_tokens',
        'reports',
        'schema_versions',
        'sensitive_words',
        'settings',
        'shop_orders',
        'shop_products',
        'team_members',
        'teams',
        'topics',
        'uploads',
        'user_actions',
        'user_badges',
        'user_coupons',
        'user_signins',
        'user_task_logs',
        'users',
        'vip_subscriptions',
        'wallet_logs',
        'wheel_prizes',
        'wheel_records',
    ];

    /** @var list<string> 期望在库中出现的全部增量列名（自动生成，至少一张表拥有即可） */
    public const REQUIRED_COLUMN_NAMES = [
        'account_state',
        'announcement',
        'announcement_at',
        'attachments',
        'audit_state',
        'avatar_frame',
        'balance',
        'biz_key',
        'category_id',
        'circle_id',
        'client_msg_id',
        'comment_count',
        'config_key',
        'config_value',
        'content_hash',
        'conversation_id',
        'cover',
        'declaration',
        'delete_scope',
        'deleted_at',
        'deleted_by',
        'discount_mode',
        'discount_value',
        'display',
        'dm_key',
        'email_verified',
        'embedding',
        'expires_at',
        'file_hash',
        'image',
        'images',
        'install_status',
        'installed_version',
        'is_ai',
        'is_anonymous',
        'is_featured',
        'is_original',
        'is_pinned',
        'is_read',
        'join_mode',
        'last_error',
        'like_count',
        'limited',
        'link_desc',
        'link_site',
        'link_title',
        'link_url',
        'location',
        'location_lat',
        'location_lng',
        'mentions',
        'muted_until',
        'notified_before_at',
        'notified_expire_at',
        'oauth_id',
        'oauth_provider',
        'origin_id',
        'pay_amount',
        'pay_days',
        'pay_type',
        'pay_vip_level',
        'pinned',
        'pinned_at',
        'plugin_name',
        'points',
        'post_id',
        'post_permission',
        'post_review',
        'priority',
        'read_at',
        'realname_verified',
        'realname_verified_at',
        'recalled_at',
        'removed_at',
        'scheduled_at',
        'slot',
        'slug',
        'source',
        'status',
        'summary',
        'tags',
        'title',
        'token_version',
        'valid_days',
        'videos',
        'view_count',
        'vip_expire_at',
        'vip_level',
        'vip_levels',
        'visibility',
        'weight',
    ];

    /**
     * 人工维护的高价值精确哨兵：这些「表.列」必须在指定表上，不能只靠同名列放行。
     * 来源于原 Migration::criticalColumnsExist() 的业务关键列，新增时请照口径补。
     *
     * @var list<array{0:string,1:string}>
     */
    public const CRITICAL_COLUMNS = [
        ['posts', 'location'],
        ['posts', 'location_lat'],
        ['posts', 'is_anonymous'],
        ['posts', 'mentions'],
        ['posts', 'declaration'],
        ['posts', 'audit_state'],
        ['posts', 'is_original'],
        ['comments', 'is_ai'],
        // V100 评论审核：status / audit_state 在自动列名段是「全库同名即放行」，
        // posts 的同名列会掩盖 comments 缺列 —— 必须精确登记到 comments 表。
        ['comments', 'status'],
        ['comments', 'audit_state'],
        ['users', 'cover'],
        ['polls', 'post_id'],
        ['badges', 'display'],
        ['shop_products', 'discount_mode'],
        ['shop_products', 'discount_value'],
        ['shop_products', 'vip_levels'],
        ['shop_products', 'valid_days'],
        ['user_coupons', 'order_id'],
        ['circles', 'pay_days'],
        ['circle_subscribes', 'notified_before_at'],
        ['circle_subscribes', 'notified_expire_at'],
        // V97 内容付费墙：CREATE TABLE 内联列扫描器抓不到，哨兵列手工登记
        ['content_products', 'preview_ratio'],
        ['content_products', 'post_type'],
        ['content_column_items', 'post_type'],
        ['content_orders', 'post_type'],
        ['content_entitlements', 'post_type'],
        ['content_entitlements', 'expire_at'],
        // V98 私信发送幂等键：手工登记的「表.列」精确哨兵，与自动生成的列名清单互补
        ['messages', 'client_msg_id'],
        // V104 私信通知关联会话：conversation_id 在全库多表同名（conversation_members /
        // message_deletions 都有），「全库同名即放行」会掩盖 notifications 缺列，
        // 必须精确登记到 notifications 表，否则新装库缺列自检发现不了。
        ['notifications', 'conversation_id'],
        // V99 私信软删除：通用列名清单里已有 deleted_at/deleted_by/delete_scope，
        // 但半残库只要任意一张表有同名列就能通过自检 —— 必须精确登记到 chat 五列
        ['conversations', 'deleted_at'],
        ['conversation_members', 'deleted_at'],
        ['messages', 'deleted_at'],
        ['messages', 'deleted_by'],
        ['messages', 'delete_scope'],
        // V58/V59 动态列名：MigrationBatchB.php 里列名是通过 PHP 变量插值拼进
        // ALTER TABLE `{$table}` ADD COLUMN `{$column}` 的 SQL，扫描器只取字符串
        // 字面量，抓不到这些列；而 REQUIRED_COLUMN_NAMES 是「全库任意表同名即放行」
        // （post_type 在 content_* 四表已存在），堵不住 posts.post_type 被 DDL 吞掉，
        // 故在这里手工登记精确的「表.列」哨兵。
        ['users', 'phone'],
        ['users', 'phone_verified_at'],
        ['users', 'gender'],
        ['users', 'birthday'],
        ['users', 'region'],
        ['users', 'user_tags'],
        ['users', 'privacy_home'],
        ['users', 'allow_search'],
        ['users', 'allow_mention'],
        ['users', 'allow_stranger_msg'],
        ['users', 'hide_online'],
        ['users', 'last_active_at'],
        ['users', 'font_scale'],
        ['users', 'msg_sound'],
        ['conversations', 'member_count'],
        ['posts', 'post_type'],
        // V101 扩展闭环三表：自动列名段是「全库同名即放行」，plugin_name/slug 必须精确落到指定表，
        // 否则任意一张插件表有同名列就能掩盖 plugin_states / pages 的半残状态。
        ['plugin_states', 'plugin_name'],
        ['plugin_configs', 'plugin_name'],
        ['pages', 'slug'],
        // V102 队列优先级：ADD COLUMN 自动列名段为「全库同名即放行」，需精确落到 jobs。
        ['jobs', 'priority'],
    ];

    /**
     * 资金 / 权益幂等唯一键（人工维护，与 CRITICAL_COLUMNS 同口径）。
     *
     * 历史事故：migrate92 的三段幂等键 DDL 全程被 catch 吞掉，站点版本号照样推进到最终态，
     * 唯一键根本没建出来 —— 而「表 / 列」维度的终态校验完全看不出来。
     * 后果是重复支付回调可重复入账、订单重放可重复发券。
     *
     * 这些键是资金链路的最后一道闸（应用层「先查后写」在并发 / 崩溃重试 / 回调重放下不可靠）。
     * 索引名由 scripts/build_migration_contract.php 交叉校验「必须真实存在于迁移源码」，
     * 防止登记出根本不存在的幽灵键。
     *
     * @var list<array{table:string,index:string,cols:list<string>,why:string}>
     */
    public const REQUIRED_UNIQUE_INDEXES = [
        ['table' => 'post_tags', 'index' => 'uk_post_tag', 'cols' => ['post_id', 'tag'], 'why' => '话题抽取 INSERT IGNORE 的幂等基础'],
        ['table' => 'wallet_logs', 'index' => 'uk_wallet_biz_key', 'cols' => ['biz_key'], 'why' => '钱包流水防重复入账'],
        ['table' => 'recharge_orders', 'index' => 'uk_trade_no', 'cols' => ['trade_no'], 'why' => '支付回调防重复入账'],
        ['table' => 'recharge_orders', 'index' => 'uk_order_no', 'cols' => ['order_no'], 'why' => '本站订单号防重复下单'],
        ['table' => 'user_coupons', 'index' => 'uk_coupon_order_slot', 'cols' => ['order_id', 'slot'], 'why' => '订单重放防重复发券'],
        ['table' => 'shop_orders', 'index' => 'uk_order_no', 'cols' => ['order_no'], 'why' => '商城订单号防重复下单'],
        ['table' => 'vip_subscriptions', 'index' => 'uk_order_no', 'cols' => ['order_no'], 'why' => '会员订阅单号防重复开通'],
        ['table' => 'jobs', 'index' => 'uk_job_dedupe', 'cols' => ['dedupe_key'], 'why' => '异步任务幂等，防重复排队与重复执行'],
        ['table' => 'conversations', 'index' => 'uk_dm_key', 'cols' => ['dm_key'], 'why' => '私信会话去重，防重复建会话'],
        // 该键真实建在 feed_feedback（V66/MigrationBatchC1.php:52），列 target_type/target_value
        // 只存在于该表；此前误登记为 user_actions 导致补建 ALTER 恒报 1072（列不存在）被吞。
        ['table' => 'feed_feedback', 'index' => 'uk_user_action_target', 'cols' => ['user_id', 'action', 'target_type', 'target_value'], 'why' => '信息流负反馈去重，防同一目标被反复刷'],
        ['table' => 'comment_marks', 'index' => 'uk_comment_mark', 'cols' => ['comment_id', 'mark_type'], 'why' => '评论标记去重'],
        // V97 内容付费墙：商品/条目/权益防重复，订单号与交易号防重复下单与回调重放
        ['table' => 'content_products', 'index' => 'uk_product', 'cols' => ['product_type', 'target_id'], 'why' => '同一帖子/专栏只允许一条付费商品，防重复定价'],
        ['table' => 'content_column_items', 'index' => 'uk_column_item', 'cols' => ['column_id', 'post_id'], 'why' => '专栏条目去重，防同一文章重复入栏'],
        ['table' => 'content_orders', 'index' => 'uk_order_no', 'cols' => ['order_no'], 'why' => '内容订单号防重复下单'],
        ['table' => 'content_orders', 'index' => 'uk_trade_no', 'cols' => ['trade_no'], 'why' => '内容订单交易号预留，防未来在线回调重复入账'],
        ['table' => 'content_entitlements', 'index' => 'uk_entitlement', 'cols' => ['user_id', 'product_type', 'target_id'], 'why' => '权益唯一凭证，防重复解锁与并发支付惊群'],
        // V98 私信：同一发送者重放同一 client_msg_id 只允许落一条消息
        ['table' => 'messages', 'index' => 'uk_msg_client', 'cols' => ['sender_id', 'client_msg_id'], 'why' => '私信发送幂等，防网络重试重复消息'],
        // V101 扩展闭环：插件配置按作用域去重、单页 slug 全局唯一
        ['table' => 'plugin_configs', 'index' => 'uk_plugin_config', 'cols' => ['plugin_name', 'config_key'], 'why' => '插件配置幂等 upsert，防同一插件同一键重复落库'],
        ['table' => 'pages', 'index' => 'uk_page_slug', 'cols' => ['slug'], 'why' => '单页标识唯一，防同一 slug 多页面导致读取歧义'],
    ];

    /**
     * 纯逻辑比对，不碰数据库 —— 便于行为级测试直接注入假的库结构。
     *
     * @param array<string,bool> $existingTables        键为表名
     * @param array<string,bool> $existingColumnNames   键为列名
     * @param array<string,bool> $existingColumnKeys    键为 "表名.列名"
     * @param array<string,bool> $existingUniqueIndexes 键为 "表名.索引名"（幂等唯一键维度，可省略）
     * @return array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes: list<string>}
     */
    public static function diff(
        array $existingTables,
        array $existingColumnNames,
        array $existingColumnKeys,
        array $existingUniqueIndexes = []
    ): array {
        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!isset($existingTables[$table])) {
                $missingTables[] = $table;
            }
        }

        $missingColumnNames = [];
        foreach (self::REQUIRED_COLUMN_NAMES as $name) {
            if (!isset($existingColumnNames[$name])) {
                $missingColumnNames[] = $name;
            }
        }

        $missingColumns = [];
        foreach (self::CRITICAL_COLUMNS as [$table, $column]) {
            if (in_array($table, $missingTables, true)) {
                continue;
            }
            $key = $table . '.' . $column;
            if (!isset($existingColumnKeys[$key])) {
                $missingColumns[] = $key;
            }
        }

        // 幂等唯一键维度。表本身缺失时不再重复报（表缺失已经报过了）。
        $missingUniqueIndexes = [];
        foreach (self::REQUIRED_UNIQUE_INDEXES as $spec) {
            if (in_array($spec['table'], $missingTables, true)) {
                continue;
            }
            $key = $spec['table'] . '.' . $spec['index'];
            if (!isset($existingUniqueIndexes[$key])) {
                $missingUniqueIndexes[] = $key;
            }
        }

        return [
            'tables' => $missingTables,
            'columnNames' => $missingColumnNames,
            'columns' => $missingColumns,
            'uniqueIndexes' => $missingUniqueIndexes,
        ];
    }

    /**
     * 结构维度是否完整 —— 表 / 列齐了没有。
     *
     * 只覆盖「结构」：唯一索引缺失**不**阻断升级，原因见 REQUIRED_UNIQUE_INDEXES 的说明
     * 与 Migration::ensureIdempotencyIndexes() 的处置（自动补建 + 巡检标记），
     * 刻意不抛异常回退版本号：那会让站点在「历史数据有重复、补建必然失败」的表上
     * 陷入「回退 → 重跑迁移 → 再失败 → 再回退」的无限循环。
     *
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} $diff
     */
    public static function isComplete(array $diff): bool
    {
        return $diff['tables'] === []
            && $diff['columnNames'] === []
            && $diff['columns'] === [];
    }

    /**
     * 结构 + 幂等唯一键全部满足。仅用于「全新安装」这类没有历史脏数据的场景。
     *
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} $diff
     */
    public static function isFullyComplete(array $diff): bool
    {
        return self::isComplete($diff)
            && ($diff['uniqueIndexes'] ?? []) === [];
    }

    /**
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} $diff
     */
    public static function describe(array $diff): string
    {
        $parts = [];
        if ($diff['tables'] !== []) {
            $parts[] = '缺表(' . count($diff['tables']) . '): ' . implode(', ', $diff['tables']);
        }
        if ($diff['columnNames'] !== []) {
            $parts[] = '缺列(' . count($diff['columnNames']) . '): ' . implode(', ', $diff['columnNames']);
        }
        if ($diff['columns'] !== []) {
            $parts[] = '关键列缺失: ' . implode(', ', $diff['columns']);
        }
        if (($diff['uniqueIndexes'] ?? []) !== []) {
            $parts[] = '缺幂等唯一键(' . count($diff['uniqueIndexes']) . '): ' . implode(', ', $diff['uniqueIndexes']);
        }
        return $parts === [] ? '契约完整' : implode(' | ', $parts);
    }
}