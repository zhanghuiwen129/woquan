<?php
/**
 * Hublog 数据库无感迁移系统
 * 访问首页时自动静默执行表结构升级，用户零感知
 *
 * P1-9 修复：
 * 1. 入口增加 SELECT GET_LOCK('hublog_migrate', 0) 多进程互斥，防止部署瞬间并发 ALTER 死锁；
 * 2. 版本号「双写」：DB schema_versions 表 + cache/.schema_version 文件，清缓存不再回退；
 *    查询优先级 DB > 文件，文件仅作为离线兜底；
 * 3. DDL 执行失败不再静默吞错，中止后续迁移并保留旧版本号（debug 模式抛异常，非 debug 写日志后 return）；
 * 4. 终态契约自检覆盖全部建表清单，避免永久半残安装。
 *
 * ============================ 文件地图 ============================
 * 本文件从上到下依次是：
 *   ① 迁移调度核心（run / GET_LOCK 互斥 / 版本读写 / 关键表与字段校验）
 *   ② buildFullSchema()：全新安装的基础建表 + 默认数据（约 500 行，单独一大块，只到 V35）
 *   ③ 增量迁移 migrate26 ~ migrate101（老库升级按版本号顺序逐个执行）
 *      —— 已拆分到 trait，降低单文件体积：
 *         · MigrationBatchA.php：migrate26 ~ migrate45
 *         · MigrationBatchB.php：migrate46 ~ migrate65 + backfillPostTagsFromContent / markExistingPollPosts
 *         · MigrationBatchC.php：migrate66 ~ migrate101
 *         主类通过 `use MigrationBatchA, MigrationBatchB, MigrationBatchC` 引入，编译期展平进主类，零行为差异。
 *   ④ 辅助方法（now() / log() 等）
 * V1~V25 的历史迁移在 MigrationHistory.php（trait），通过 `use MigrationHistory` 引入。
 *
 * ----------------------------------------------------------------
 * 【新增表 / 字段时必须做的两件事】（漏一件就会埋线上事故）
 *   1. 写一个新的 migrateNN()，并把 $currentVersion 加到 NN；
 *   2. 执行 `php scripts/build_migration_contract.php --write` 重新生成 MigrationContract.php。
 *      —— 终态契约由脚本从本文件 + 4 个 trait 的 SQL 字符串自动生成，不再手工登记两份清单：
 *         run() 在全新安装和升级结束时都会用契约校验一次，缺表/缺列会抛异常并真实回退
 *         DB 版本号让下次请求重跑。漏跑生成器 = CI 直接红（MigrationContractTest）。
 *         历史教训：手工清单曾长期只登记 24 张表、16 列，漏登 4 表 + 数十列，站点带着残库运行。
 *
 * 【不需要再手改 buildFullSchema()】
 *   全新安装 = buildFullSchema() 建 V1~V35 基础表 + 默认数据，
 *              然后自动循环补跑 migrate36 ~ $currentVersion 的全部增量迁移。
 *   因为是循环到 $currentVersion，以后新增 migrate62/65… 全新安装会自动带上，
 *   不会因为「忘了往全量建表清单里补」而让新装站点缺表。
 *   buildFullSchema() 保持「一次成型」的基础表快速路径定位，不再需要跟着改。
 * ----------------------------------------------------------------
 * 【其他约定】
 *   - 每条 DDL 都用 information_schema 先判存在性再执行，保证重复执行幂等；
 *   - 迁移里不要用 Database::connection()（不存在），只有 getInstance()/query/fetchOne/
 *     fetchAll/fetchColumn/insert/update/delete；
 *   - 每句 DDL 单独 try/catch 会掩盖错误；现在由 run() 的终态契约校验统一兜底
 *     （223 个 catch 吞掉的失败会在同一次请求里暴露，版本号不会推进到成功态）。
 * ================================================================
 */

namespace Hublog\Core;

class Migration
{
    use MigrationHistory;
    use MigrationBatchA, MigrationBatchB, MigrationBatchC;
    private static string $versionFile = __DIR__ . '/../../cache/.schema_version';
    // P0-9：本地「已验证」标记文件（version + 时间戳）。原实现每个请求都要
    // GET_LOCK + MAX(version) + information_schema ×15+ ≈ 19 次 SQL 才发现
    // 「版本齐、表字段都在、直接跳过」；稳态下这是纯地板开销。
    // 改为：验证通过后在本地写标记，TTL 内（见 VERIFY_TTL）0 次 DB 查询直接返回。
    // 安全性：标记语义 = 「本机在 TTL 秒内完整跑过终态契约校验且通过」。
    // 新代码部署（currentVersion +1）或标记过期 → 自动回到完整校验路径。
    private static string $verifiedFile = __DIR__ . '/../../cache/.schema_verified';
    // 迁移治理：失败标记文件。生产 APP_DEBUG=false 时错误只进 error_log 太容易被忽略，
    // 额外落一个可巡检的标记（时间 / 版本 / 错误摘要），供运维与未来的 /health 端点读取。
    private static string $failureFile = __DIR__ . '/../../cache/.migration_failed';
    // P0-1：幂等唯一键缺失标记。migrate92 的幂等键 DDL 曾被 catch 吞掉，
    // 表 / 列维度的终态校验查不出「唯一键没建出来」，而它直接决定重复支付回调会不会重复入账。
    // 补建失败（历史数据已有重复值）时落这个可巡检标记，供运维去重后重试。
    private static string $idempotencyFile = __DIR__ . '/../../cache/.idempotency_missing';
    // 幂等键补建失败的退避窗口（秒）。窗口内不再重复尝试同一个键，
    // 避免每次请求都刷一遍同样的 ALTER 失败日志。
    private const IDEMPOTENCY_BACKOFF = 3600;
    // 标记有效期（秒）。多机部署时最坏 TTL 秒内不感知他机已完成的迁移，
    // 部署本身以 currentVersion 递增为准，不受此值影响。
    private const VERIFY_TTL = 300;
    // V54: 账号注销后保留内容（users.deleted_at 区分「已注销」与「被封禁」）
    // V81: 校准 posts.comment_count（AI 自动评论等直接 insert 的评论从未计入冗余列）
    // V82: 徽章系统（badges / user_badges / badge_logs 三表 + 38 枚徽章种子数据）
    // V92: 资金 / 权益类表补幂等唯一键（wallet_logs.biz_key / recharge_orders.trade_no / user_coupons slot）
    // V93: posts 全文索引按数据库能力分级创建（ngram → 普通 FULLTEXT → LIKE 降级）
    // V94: 新增 jobs 异步任务表（视频压缩等耗时任务移出请求热路径）
    // V95: 分类关注表 category_follows + 话题头图字段 topics.cover
    // V96: 付费圈子订阅周期可配（circles.pay_days）+ 到期提醒幂等标记 + 清退索引
    // V97: 内容付费墙（content_products/columns/column_items/orders/entitlements 五表）
    // V98: 私信发送幂等键（messages.client_msg_id + uk_msg_client），防网络重试重复消息
    // V99: 私信软删除语义统一（V52 五列硬自检 + 存量脏数据幂等修复 + 终态哨兵），无新增业务列
    // V100: 评论审核状态列（comments.status/audit_state）+ AI 配额预扣状态列（ai_usage_logs.status）
    // V101: 扩展闭环基础设施三表（plugin_states/plugin_configs/pages）+ 协议占位种子
    private static int $currentVersion = 101;
    private static bool $checked = false;
    /** 全新安装快速路径同请求只跑一次（install.php 只调用一次，这里防重放） */
    private static bool $freshChecked = false;

    // 安装诊断计时：默认关闭，仅 install.php 在全新安装时开启，生产请求零开销。
    // 用于在真实部署环境（如跨境服务器）定位全新安装各阶段耗时，避免本地数据外推失真。
    private static float $benchStart = 0.0;
    private static array $benchTimes = [];
    public static function enableBench(): void { self::$benchStart = microtime(true); self::$benchTimes = []; }
    private static function benchMark(string $label): void
    {
        if (self::$benchStart > 0.0) {
            self::$benchTimes[$label] = round((microtime(true) - self::$benchStart) * 1000, 1);
        }
    }
    public static function benchResult(): array { return self::$benchTimes; }
    // 注意：不要写成 `private const string XXX`。类型化类常量是 PHP 8.3 才支持的语法，
    // 在 PHP 8.2 及以下会导致解析期致命错误（syntax error, unexpected identifier），
    // 且发生在类加载阶段，try/catch 完全兜不住，直接表现为整站 500 / 安装失败。
    private const MIGRATE_LOCK_NAME = 'hublog_migrate';
    private const MIGRATE_LOCK_TIMEOUT = 0; // 0 = 拿不到锁立刻返回，不排队等待

    /**
     * 执行迁移检查与升级
     * 应在应用初始化时调用一次（同请求内仅执行一次）
     */
    public static function run(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        // P0-9：快速路径——本地已验证标记新鲜（版本≥当前版本 且在 TTL 内）→ 直接返回。
        // 必须早于 acquireLock()：稳态下每请求 0 次迁移相关 SQL。
        if (self::verificationCacheFresh()) {
            return;
        }

        // P1-9：尝试获取 MySQL 命名锁（GET_LOCK 基于连接级别，PDO 断连后自动释放）。
        // 拿不到锁说明另一个进程正在执行迁移，本进程直接跳过，避免并发 ALTER 相互锁等待。
        $lockState = self::acquireLock();
        $lockAcquired = $lockState === 'acquired';
        try {
            $installedVersion = self::getInstalledVersion();

            // P1-3：拿不到锁时**不要**再做终态契约自检。
            // 原实现把 schemaDiff() 放在锁判断之前，TTL 到期瞬间到达的 N 个并发请求
            // 会一起打 information_schema（惊群）——恰好是最该避免的时刻。
            // 现在：锁被别人持有（busy）→ 本进程直接退出，持有者会写好标记，下个请求走快路径；
            //       锁因 DB 异常拿不到（error）→ 只允许做只读自检，绝不碰 DDL / 版本号。
            if ($lockState === 'busy') {
                error_log(
                    '[Hublog Migration] 其他进程正在执行迁移/校验，本进程跳过。当前版本='
                    . $installedVersion
                );
                return;
            }

            // 即使版本号匹配，也用终态契约校验一次（防止版本残留 / DDL 被 catch 吞掉后跳过迁移）
            if ($installedVersion >= self::$currentVersion) {
                $diff = self::schemaDiff();
                if (MigrationContract::isComplete($diff)) {
                    // P0-1：结构齐了但幂等唯一键可能没建出来 —— 直接补建，不阻断稳态。
                    // 无锁时不补建（ALTER 是 DDL，必须串行）
                    if ($lockAcquired) {
                        self::ensureIdempotencyIndexes($diff);
                    }
                    // 版本齐、契约满足，直接跳过（P0-9：刷新本地已验证标记）
                    self::markVerified($installedVersion);
                    self::clearFailureMarker();
                    return;
                }
                if (!$lockAcquired) {
                    // 无锁时不能改版本号：回退会让全站反复重跑迁移，只如实记录问题。
                    self::logContractFailure('稳态自检未通过（无迁移锁，仅告警）', $diff, $installedVersion);
                    self::writeFailureMarker(MigrationContract::describe($diff));
                    return;
                }
                // 缺表回退到 0 走全量建表，仅缺列回退到 25 走增量补齐。
                // saveVersion() 现在会真正删除 DB 中更高的版本记录，多机部署一致。
                $rollback = $diff['tables'] !== [] ? 0 : 25;
                self::logContractFailure('稳态自检未通过', $diff, $installedVersion);
                self::saveVersion($rollback);
                $installedVersion = $rollback; // 局部变量置回退值，本请求继续重跑
            }

            // 拿不到锁（GET_LOCK 异常）时不能执行任何 DDL —— 迁移必须串行。
            if (!$lockAcquired) {
                error_log('[Hublog Migration] 未持有迁移锁，跳过本次升级。当前版本=' . $installedVersion);
                return;
            }

            // 全新安装：走全量建表快速路径 + 补跑后续全部增量迁移
            if ($installedVersion < 0) {
                // ① 一次性建出 V1~V35 的基础表 + 默认配置 + ai_assistant 用户
                self::buildFullSchema();
                self::benchMark('schema_built');

                // ② 补跑 36 到当前版本的全部增量迁移。
                //    旧实现这里写死只跑 migrate36~43，结果 V44 之后新建的 25 张表
                //    （refresh_tokens / app_versions / push_tokens / circles / polls /
                //     activities / teams / drafts / call_records …）在全新安装的第一个请求里
                //    根本不存在，要等第二次请求被终态契约自检发现才回退重跑，
                //    中间是个「库不是最终态」的窗口。
                //
                //    改成循环而不是写死一串调用：以后新增 migrate62/65… 全新安装会自动带上，
                //    不会再出现「新增了表但新装站点没有」这类问题。
                //
                //    安全性：buildFullSchema 建的是最终态表结构，而每个 migrateNN 都是幂等的
                //    （CREATE TABLE IF NOT EXISTS + information_schema 先判列/判索引），
                //    在已经是最终态的表上再跑一遍等于 no-op，不会重复加列或报错。
                // 元数据快照加速：全新安装的 66 个迁移会发 ~131 次 information_schema
                // 存在性探测（本地小库 0.19ms/次，数据字典大的服务器可达上百ms/次，
                // 是实测 16.6 秒的主因）。迁移期间开启快照，一次性批量拉全库元数据，
                // fetchOne/fetchColumn 的存在性探测直接命中内存。只缓存"存在"结论，
                // "不存在"一律走真实查询兜底，保证迁移中途新建的对象能被正确探测到。
                // try/finally 保证无论成功或异常都关闭，不影响后续请求。
                \Hublog\Core\Database::enableSchemaSnapshot();
                try {
                    for ($v = 36; $v <= self::$currentVersion; $v++) {
                        self::applyMigration($v);
                    }
                } finally {
                    // 快照命中统计写入诊断结果：完成页可见，用于判断是否加载到新代码
                    self::$benchTimes['snap_version'] = \Hublog\Core\Database::SNAP_VERSION;
                    self::$benchTimes['snap_stats'] = json_encode(\Hublog\Core\Database::snapshotStats());
                    \Hublog\Core\Database::disableSchemaSnapshot();
                }
                self::benchMark('migrations_done');

                // ③ 装完立刻用终态契约自检：223 个 catch 吞掉的 DDL 失败会在这里被兜住，
                //    绝不把「半残库」写成已安装版本
                $diff = self::schemaDiff();
                self::benchMark('schema_checked');
                if (!MigrationContract::isComplete($diff)) {
                    self::saveVersion(0);
                    throw new \RuntimeException(
                        '全新安装后终态契约未满足: ' . MigrationContract::describe($diff)
                    );
                }

                // P0-1：全新安装没有历史脏数据，幂等唯一键必须全部就位，
                //    缺一个就说明建表 DDL 没成功 —— 这里可以安全地直接失败。
                if (!MigrationContract::isFullyComplete($diff)) {
                    throw new \RuntimeException(
                        '全新安装后幂等唯一键未满足: ' . MigrationContract::describe($diff)
                    );
                }

                self::saveVersion(self::$currentVersion);
                self::clearFailureMarker();
                self::benchMark('version_saved');
                return;
            }

            $upgradeFrom = $installedVersion;
            for ($v = $upgradeFrom + 1; $v <= self::$currentVersion; $v++) {
                // P1-9 + 迁移治理：方法缺失（非登记跳号）立即中止，不再静默推进版本号
                self::applyMigration($v);
                // 每成功一个版本记录一次，中途失败时保留上一个成功版本，下次从断点续跑
                self::saveVersion($v);
            }

            // 升级跑完立刻做终态契约自检。任何被 migrateNN 内部 catch 吞掉的 DDL 失败
            // 都会在这里暴露：版本号真实回退到升级前，绝不把半残库标记成最终态。
            $diff = self::schemaDiff();
            if (!MigrationContract::isComplete($diff)) {
                self::saveVersion($upgradeFrom);
                throw new \RuntimeException(
                    '升级后终态契约未满足（已回退到版本 ' . $upgradeFrom . '）: '
                    . MigrationContract::describe($diff)
                );
            }

            // P0-1：升级路径上幂等唯一键缺失不阻断（历史数据可能已有重复值，强行补建必然失败），
            //    改为自动补建 + 失败落巡检标记。
            self::ensureIdempotencyIndexes($diff);

            self::markVerified(self::$currentVersion);
            self::clearFailureMarker();
        } catch (\Throwable $e) {
            // 迁移治理：生产环境不再「只吞不报」。除完整日志外写失败标记文件，
            // 供运维巡检 / 未来的 /health 端点读取；debug 模式仍抛异常便于开发排查。
            $detail = '[Hublog Migration Error] 迁移失败，已保留/回退上次成功版本号。'
                . ' 错误: ' . $e->getMessage()
                . ' | 位置: ' . basename($e->getFile()) . ':' . $e->getLine()
                . ' | 目标版本: ' . self::$currentVersion;
            error_log($detail);
            self::writeFailureMarker($e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
            return;
        } finally {
            if ($lockAcquired) {
                self::releaseLock();
            }
        }
    }

    /**
     * 全新安装快速路径（install.php 专用）。
     *
     * 原来的新装流程是 buildFullSchema() 建 18 张基础表 + 循环跑 66 个 migrateNN。
     * 每个 migrateNN 都要先探测 information_schema（133 次），在数据字典大的服务器上
     * 实测占安装耗时 90%（18 秒里 16.6 秒）。
     *
     * 新流程：
     *   ① 一次性执行终态 DDL 基线（85 张表，CREATE TABLE IF NOT EXISTS，无数据）；
     *   ② 补基础种子（V1~V35 默认 settings + ai_assistant），与老路径共用 seedFreshBase()；
     *   ③ 开启元数据快照后补跑 migrate36~101 —— 这一步只为写种子数据（V36+ 的
     *      settings / 徽章 / 插件协议等散落在各 migrateNN 里，保持代码单一数据源），
     *      所有「对象已存在就跳过 DDL」的判断都被快照在内存里回答；
     *   ④ 终态契约自检（表 / 列 / 幂等唯一键）+ 写入版本号。
     *
     * 老库升级完全不走这里，仍用 run() 的按版本增量迁移，行为零变化。
     * 计时打点沿用 enableBench()，完成页耗时明细不受影响。
     */
    public static function runFreshInstall(): void
    {
        if (self::$freshChecked) {
            return;
        }
        self::$freshChecked = true;

        if (FreshInstallSchema::SCHEMA_VERSION !== self::$currentVersion) {
            throw new \RuntimeException(
                '终态 DDL 基线版本 ' . FreshInstallSchema::SCHEMA_VERSION
                . ' 与迁移版本 ' . self::$currentVersion . ' 不一致，请在终态库重跑 scripts/build_fresh_schema.php'
            );
        }

        // ① 终态 DDL：19 张表带外键，先关外键检查；finally 保证恢复
        $pdo = Database::getInstance();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (FreshInstallSchema::STATEMENTS as $sql) {
                $pdo->exec($sql);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        self::benchMark('schema_built');

        // ② 基础种子（settings 默认值 + ai_assistant）
        self::seedFreshBase();
        self::benchMark('base_seeded');

        // ③ 补跑增量迁移拿 V36+ 种子。快照在终态 DDL 之后拍，
        //    迁移里「表/列/索引已存在」的探测几乎全部内存命中，只剩真实种子 INSERT。
        Database::enableSchemaSnapshot();
        try {
            for ($v = 36; $v <= self::$currentVersion; $v++) {
                self::applyMigration($v);
            }
        } finally {
            self::$benchTimes['snap_version'] = Database::SNAP_VERSION;
            self::$benchTimes['snap_stats'] = json_encode(Database::snapshotStats());
            Database::disableSchemaSnapshot();
        }
        self::benchMark('migrations_done');

        // ④ 终态自检：DDL 被吞 / 基线过期都会在这里暴露，绝不把半残库标成成功
        $diff = self::schemaDiff();
        self::benchMark('schema_checked');
        if (!MigrationContract::isComplete($diff)) {
            self::saveVersion(0);
            throw new \RuntimeException(
                '全新安装后终态契约未满足: ' . MigrationContract::describe($diff)
            );
        }
        if (!MigrationContract::isFullyComplete($diff)) {
            throw new \RuntimeException(
                '全新安装后幂等唯一键未满足: ' . MigrationContract::describe($diff)
            );
        }

        self::saveVersion(self::$currentVersion);
        self::clearFailureMarker();
        self::benchMark('version_saved');
    }

    /**
     * P1-9：获取 MySQL 命名锁 GET_LOCK，防止多 PHP-FPM 进程并发迁移造成死锁
     *
     * P1-3：返回三种状态而不只是 bool，调用方据此区分「别人正在干」与「锁本身出问题了」：
     *   - acquired：拿到锁，可以执行迁移与契约自检；
     *   - busy：锁被别人持有（另一个进程正在迁移/校验）→ 本进程应直接退出，
     *           绝不能再去打 information_schema 造成惊群；
     *   - error：GET_LOCK 自身失败（DB 异常）→ 锁不可信，本进程只做只读检查、不碰 DDL。
     *
     * @return 'acquired'|'busy'|'error'
     */
    private static function acquireLock(): string
    {
        try {
            $result = Database::fetchColumn(
                "SELECT GET_LOCK(?, ?)",
                [self::MIGRATE_LOCK_NAME, self::MIGRATE_LOCK_TIMEOUT]
            );
            return (int)$result === 1 ? 'acquired' : 'busy';
        } catch (\Throwable $e) {
            error_log('[Hublog Migration] GET_LOCK 失败，降级为无锁执行: ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * P1-9：释放 GET_LOCK
     */
    private static function releaseLock(): void
    {
        try {
            Database::fetchColumn("SELECT RELEASE_LOCK(?)", [self::MIGRATE_LOCK_NAME]);
        } catch (\Throwable $e) {
            // 释放失败不需要阻断；连接断开后会自动释放
        }
    }

    /**
     * 获取已安装的版本号
     * P1-9：优先从 schema_versions 表读取（DB 版本号不受缓存目录清理影响），
     * 表不存在或无记录时降级回 cache/.schema_version 文件。
     */
    private static function getInstalledVersion(): int
    {
        // DB 优先：如果 schema_versions 表存在且有记录，用最大版本号
        try {
            $svExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_versions' LIMIT 1"
            );
            if ($svExists) {
                $dbVersion = Database::fetchColumn("SELECT MAX(version) FROM schema_versions");
                if ($dbVersion !== null && $dbVersion !== false) {
                    $v = (int)$dbVersion;
                    if ($v > 0) {
                        // DB 记录存在，同步写回文件兜底（清缓存后仍可恢复）
                        self::writeFileVersion($v);
                        return $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            // DB 连接失败等，降级到文件
        }

        // 兜底：读取本地文件版本号
        if (file_exists(self::$versionFile)) {
            return (int)file_get_contents(self::$versionFile);
        }
        // 首次运行：检查 users 表是否存在判断是否为全新安装
        try {
            $tableExists = Database::fetchColumn("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' LIMIT 1");
            return $tableExists ? 0 : -1;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * 迁移治理：双写版本号，且 DB 版本号可真正下降。
     *
     * 旧实现只有 INSERT IGNORE，而 getInstalledVersion() 读 MAX(version) 且 DB 优先，
     * 于是所有「回退重跑」实际只改了文件版本与 .schema_verified，DB 仍停在 91：
     * 多机部署下别的机器读到高版本就不会重跑，稳态 0 SQL 快路径也永远回不来。
     * 现在先删掉高于目标版本的记录，让 MAX(version) 与文件版本一致。
     *
     * DB 写失败必须抛：静默降级会让回退语义失效，站点会带着「看起来已升级」的假象运行。
     */
    private static function saveVersion(int $version): void
    {
        try {
            self::writeDbVersion($version);
        } catch (\Throwable $e) {
            // schema_versions 被误删时，自检回退的第一步就会卡在这里，
            // 站点将永远无法重跑全量建表。先补建再重试一次（跨库兼容 DDL）。
            try {
                Database::getInstance()->exec(
                    "CREATE TABLE IF NOT EXISTS `schema_versions` (
                        `version` int(11) NOT NULL PRIMARY KEY,
                        `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                self::writeDbVersion($version);
            } catch (\Throwable $inner) {
                $msg = '[Hublog Migration] schema_versions 写入失败，迁移中止: ' . $inner->getMessage();
                error_log($msg);
                throw new \RuntimeException($msg, 0, $inner);
            }
        }

        self::writeFileVersion($version);

        // P0-9：同步刷新「已验证」标记。注意用真实写入的 $version（不是 currentVersion）：
        // 回退到 0/25 时标记版本也 < currentVersion，下一请求自然走完整校验。
        self::markVerified($version);
    }

    /**
     * 删掉 >= 目标版本的记录（真回退 + 同版本幂等），再普通 INSERT。
     * 不再依赖 MySQL 专有的 INSERT IGNORE；时间由 PHP 统一生成，避免双时间源差 8 小时。
     */
    private static function writeDbVersion(int $version): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM schema_versions WHERE version >= ?");
        $stmt->execute([$version]);
        $stmt = $pdo->prepare(
            "INSERT INTO schema_versions (version, applied_at) VALUES (?, ?)"
        );
        $stmt->execute([$version, self::now()]);
    }

    /**
     * P0-9：本地已验证标记是否新鲜（version ≥ currentVersion 且距上次验证 < VERIFY_TTL）
     */
    private static function verificationCacheFresh(): bool
    {
        if (!is_file(self::$verifiedFile)) {
            return false;
        }
        $raw = @file_get_contents(self::$verifiedFile);
        if ($raw === false || $raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['version'], $data['time'])) {
            return false;
        }
        if ((int)$data['version'] < self::$currentVersion) {
            return false;
        }
        if (time() - (int)$data['time'] > self::VERIFY_TTL) {
            return false;
        }
        return true;
    }

    /**
     * P0-9：写「已验证」标记（原子替换，避免读到写了一半的 JSON）
     */
    private static function markVerified(int $version): void
    {
        $dir = dirname(self::$verifiedFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode(['version' => $version, 'time' => time()]);
        if ($payload === false) {
            return;
        }
        @file_put_contents(self::$verifiedFile, $payload, LOCK_EX);
    }

    /**
     * 仅写文件版本号（不操作 DB），供 DB 读取后同步兜底或作为旧库兼容
     */
    private static function writeFileVersion(int $version): void
    {
        $dir = dirname(self::$versionFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::$versionFile, (string)$version, LOCK_EX);
    }

    /**
     * 终态契约自检：一次取回所有关心的表 / 列，交给 MigrationContract::diff() 比对。
     *
     * 取代原来「手工登记 71 张表 + 16 列、每列一次 information_schema 查询」的实现：
     *   - 清单由 scripts/build_migration_contract.php 从源码生成，漏登不再可能；
     *   - 两条批量 SQL 替代 1 + 16 条，稳态仍由 .schema_verified 的 TTL 快路径挡住；
     *   - DB 查不动时直接抛（交给 run() 外层 catch 记录），不再伪装成「缺表」触发全量重跑。
     *
     * @return array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes: list<string>}
     */
    private static function schemaDiff(): array
    {
        $pdo = Database::getInstance();
        $existingTables = [];

        $tables = MigrationContract::REQUIRED_TABLES;
        $ph = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$ph})"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $existingTables[strtolower((string)$name)] = true;
        }

        // CRITICAL_COLUMNS 里可能有不在增量列名集合中的列（如 user_coupons.order_id），一并取回
        $names = array_values(array_unique(array_merge(
            MigrationContract::REQUIRED_COLUMN_NAMES,
            array_column(MigrationContract::CRITICAL_COLUMNS, 1)
        )));
        $ph = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ({$ph})"
        );
        $stmt->execute($names);
        $existingColumnNames = [];
        $existingColumnKeys = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $table = strtolower((string)$row[0]);
            $column = strtolower((string)$row[1]);
            $existingColumnNames[$column] = true;
            $existingColumnKeys[$table . '.' . $column] = true;
        }

        return MigrationContract::diff(
            $existingTables,
            $existingColumnNames,
            $existingColumnKeys,
            self::existingUniqueIndexes()
        );
    }

    /**
     * 幂等唯一键现状：键为 "表名.索引名"。
     *
     * 只查契约关心的表 × 索引名，命中即视为该唯一键存在。
     * NON_UNIQUE = 0 才是真唯一索引（普通索引不算）。
     */
    private static function existingUniqueIndexes(): array
    {
        $indexTables = array_values(array_unique(array_column(
            MigrationContract::REQUIRED_UNIQUE_INDEXES,
            'table'
        )));
        $indexNames = array_values(array_unique(array_column(
            MigrationContract::REQUIRED_UNIQUE_INDEXES,
            'index'
        )));
        if ($indexTables === [] || $indexNames === []) {
            return [];
        }

        $phTables = implode(',', array_fill(0, count($indexTables), '?'));
        $phNames = implode(',', array_fill(0, count($indexNames), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
               AND TABLE_NAME IN ({$phTables}) AND INDEX_NAME IN ({$phNames})"
        );
        $stmt->execute(array_merge($indexTables, $indexNames));

        $existing = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            // STATISTICS 每个索引列一行，这里按键收敛成一份
            $existing[strtolower((string)$row[0]) . '.' . strtolower((string)$row[1])] = true;
        }
        return $existing;
    }

    /**
     * 幂等唯一键补建（P0-1）。
     *
     * 背景：migrate92 的三段幂等键 DDL 全程被 catch 吞掉，站点版本号照样推进到最终态，
     * 而「表 / 列」维度的终态校验查不出「唯一键没建出来」——
     * 结果重复支付回调可重复入账、订单重放可重复发券。
     *
     * 处置策略（刻意不抛异常回退版本号）：
     *   1. 直接补建缺失的唯一键；
     *   2. 补建失败（通常是历史数据已有重复值）→ 落可巡检标记文件 + error_log，人工去重后重试；
     *   3. 失败项进入退避窗口，窗口内不再重复尝试，避免每轮校验都刷一遍 ALTER 失败日志。
     *
     * 为什么不用 throw 回退：回退会让站点在「历史数据必然重复」的表上陷入
     * 「回退 → 重跑迁移 → 补建再失败 → 再回退」的无限循环，整站直接不可用。
     * 资金幂等在应用层还有第二道防线（先查后写），比让整站瘫痪划算。
     *
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} $diff
     * @return list<string> 补建后仍然缺失的键
     */
    private static function ensureIdempotencyIndexes(array $diff): array
    {
        $missing = $diff['uniqueIndexes'] ?? [];
        if ($missing === []) {
            self::clearIdempotencyMarker();
            return [];
        }

        $backoff = self::idempotencyBackoff();
        $now = time();
        $stillMissing = [];
        $failed = [];

        foreach (MigrationContract::REQUIRED_UNIQUE_INDEXES as $spec) {
            $key = $spec['table'] . '.' . $spec['index'];
            if (!in_array($key, $missing, true)) {
                continue;
            }
            if ($backoff['until'] > $now && isset($backoff['failed'][$key])) {
                $stillMissing[] = $key;
                continue;
            }

            $sql = 'ALTER TABLE `' . $spec['table'] . '` ADD UNIQUE KEY `'
                . $spec['index'] . '` (`' . implode('`, `', $spec['cols']) . '`)';
            try {
                Database::getInstance()->exec($sql);
            } catch (\Throwable $e) {
                $failed[$key] = substr($e->getMessage(), 0, 200);
                $stillMissing[] = $key;
                error_log(
                    '[Hublog Migration] 幂等唯一键补建失败: ' . $key
                    . '（' . $spec['why'] . '）| ' . $e->getMessage()
                );
            }
        }

        if ($stillMissing === []) {
            self::clearIdempotencyMarker();
            return [];
        }
        self::writeIdempotencyMarker($stillMissing, $failed);
        return $stillMissing;
    }

    /**
     * 读取幂等键补建的退避状态。
     *
     * @return array{until:int, failed:array<string,bool>}
     */
    private static function idempotencyBackoff(): array
    {
        $raw = is_file(self::$idempotencyFile) ? @file_get_contents(self::$idempotencyFile) : false;
        if ($raw === false || $raw === '') {
            return ['until' => 0, 'failed' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['until' => 0, 'failed' => []];
        }
        $failed = [];
        foreach (($data['errors'] ?? []) as $key => $msg) {
            $failed[(string)$key] = true;
        }
        return ['until' => (int)($data['retry_after'] ?? 0), 'failed' => $failed];
    }

    /**
     * @param list<string> $missing
     * @param array<string,string> $errors
     */
    private static function writeIdempotencyMarker(array $missing, array $errors): void
    {
        $dir = dirname(self::$idempotencyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            'time' => date('c'),
            'missing' => $missing,
            'errors' => $errors,
            'retry_after' => time() + self::IDEMPOTENCY_BACKOFF,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents(self::$idempotencyFile, $payload, LOCK_EX);
        }
    }

    private static function clearIdempotencyMarker(): void
    {
        if (is_file(self::$idempotencyFile)) {
            @unlink(self::$idempotencyFile);
        }
    }

    /**
     * 幂等唯一键缺失状态（供 /health 等运维端点暴露，无需数据库连接）。
     *
     * @return array{time:string,missing:list<string>,errors:array<string,string>}|null
     */
    public static function getIdempotencyStatus(): ?array
    {
        if (!is_file(self::$idempotencyFile)) {
            return null;
        }
        $raw = @file_get_contents(self::$idempotencyFile);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['time' => '', 'missing' => [], 'errors' => []];
        }
        return [
            'time' => (string)($decoded['time'] ?? ''),
            'missing' => array_values(array_map('strval', (array)($decoded['missing'] ?? []))),
            'errors' => (array)($decoded['errors'] ?? []),
        ];
    }

    /**
     * 执行单个版本的迁移。方法缺失且不属于登记跳号 → 抛异常中止，绝不静默推进版本号。
     *
     * 历史事故：V88 的函数体被误删，run() 的 method_exists 守卫 + 守卫外照写版本号，
     * 导致 posts.declaration 永远缺失、首页 pinned 接口 1054 报错。
     */
    private static function applyMigration(int $version): void
    {
        $method = "migrate{$version}";
        if (method_exists(self::class, $method)) {
            self::$method();
            return;
        }
        if (in_array($version, MigrationContract::INTENTIONAL_GAPS, true)) {
            return;
        }
        throw new \RuntimeException(
            "迁移方法 {$method} 缺失（非登记跳号），已中止升级以防止静默跳过 DDL"
        );
    }

    /** 清空本地版本 / 已验证 / 失败标记，供 install.php 强制走全新安装 */
    public static function resetState(): void
    {
        foreach ([self::$versionFile, self::$verifiedFile, self::$failureFile] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array{tables: list<string>, columnNames: list<string>, columns: list<string>} $diff */
    private static function logContractFailure(string $stage, array $diff, int $version): void
    {
        error_log(
            '[Hublog Migration] ' . $stage . '，当前版本=' . $version
            . ' | ' . MigrationContract::describe($diff)
        );
    }

    /** 生产失败不再只进 error_log：落一个可巡检的标记文件 */
    private static function writeFailureMarker(string $message): void
    {
        $dir = dirname(self::$failureFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode([
            'time' => date('c'),
            'target_version' => self::$currentVersion,
            'error' => substr($message, 0, 500),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents(self::$failureFile, $payload, LOCK_EX);
        }
    }

    private static function clearFailureMarker(): void
    {
        if (is_file(self::$failureFile)) {
            @unlink(self::$failureFile);
        }
    }

    /**
     * 读取迁移失败标记（供 /health 等运维端点暴露，无需数据库连接）。
     *
     * 返回最近一次迁移失败的摘要；无失败返回 null。
     * 这样「迁移失败不再静默」从「写日志 + 落标记文件」进一步接到可监控的通道：
     * 运维探针打 /health 就能看到迁移是否处于失败态，而不是事后翻 error_log。
     *
     * @return array{time:string,target_version:int,error:string}|null
     */
    public static function getFailure(): ?array
    {
        if (!is_file(self::$failureFile)) {
            return null;
        }
        $raw = @file_get_contents(self::$failureFile);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['time' => '', 'target_version' => self::$currentVersion, 'error' => '(无法解析失败标记)'];
        }
        return $decoded;
    }


    // ==================== 全量建表（新安装快速路径） ====================

    /**
     * 创建 posts 全文索引，按数据库能力分级降级。
     *
     * 详见 buildFullSchema() 里调用处的说明：
     *   ngram（MySQL）→ 普通 FULLTEXT（MariaDB）→ 不建（退回 LIKE 检索）。
     * 全程不抛异常 —— 全文索引只影响搜索体验，不该让站点装不起来。
     */
    private static function ensurePostsFulltextIndex(): void
    {
        try {
            $exists = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_posts_fulltext'"
            );
            if ($exists) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V93] 检查 idx_posts_fulltext 失败: ' . $e->getMessage());
            return;
        }

        // ① 首选 ngram（MySQL 5.7+）：中文分词检索
        if (self::supportsNgramParser()) {
            try {
                Database::getInstance()->exec(
                    "ALTER TABLE `posts` ADD FULLTEXT INDEX `idx_posts_fulltext` (`title`, `content`) WITH PARSER ngram"
                );
                self::log('V93：posts 全文索引已建（ngram parser）');
                return;
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V93] ngram 全文索引创建失败，降级为普通 FULLTEXT: ' . $e->getMessage());
            }
        }

        // ② 降级：普通 FULLTEXT（MariaDB 支持，只是没有中文分词）
        try {
            Database::getInstance()->exec(
                "ALTER TABLE `posts` ADD FULLTEXT INDEX `idx_posts_fulltext` (`title`, `content`)"
            );
            self::log('V93：posts 全文索引已建（普通 FULLTEXT，无中文分词）');
        } catch (\Throwable $e) {
            // ③ 连普通 FULLTEXT 都不支持 → 不建索引，SearchController 会退回 LIKE 匹配
            error_log('[Hublog Migration V93] 全文索引无法创建，搜索将降级为 LIKE 匹配: ' . $e->getMessage());
        }
    }

    /**
     * 当前数据库是否支持 ngram 分词器（MySQL 5.7+ / MariaDB 10.0.5+ 但需自行安装插件）。
     *
     * 判定方式：查 information_schema.PLUGINS 里 ngram 插件是否存在且为 ACTIVE。
     * 查不到表/查不到行都视为「不支持」，走普通 FULLTEXT 降级。
     */
    private static function supportsNgramParser(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $row = Database::fetchOne(
                "SELECT PLUGIN_STATUS FROM information_schema.PLUGINS
                 WHERE PLUGIN_NAME = 'ngram' LIMIT 1"
            );
            $cached = ($row && strtoupper((string)($row['PLUGIN_STATUS'] ?? '')) === 'ACTIVE');
        } catch (\Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * 基础建表：合并 V1-V35 所有表结构的最终态，用于全新安装
     *
     * 触发条件：run() 检测到 installedVersion = -1（无 users 表 + 无版本文件）
     * 优势：一次性创建 21 张基础表（V1~V35）+ 默认配置 + AI 用户，跳过 V1~V35 共 35 个增量迁移，
     *       显著降低安装耗时，避免 PHP-FPM 超时导致的 502。
     * 兼容：所有 CREATE TABLE 使用 IF NOT EXISTS，重装场景安全；
     *       ai_assistant 显式指定 id=2，id=1 留给 install.php 插入的管理员。
     *
     * ⚠ 这里只到 V35。V36 之后的表 / 字段 / 索引由 run() 在本方法之后循环补跑
     *   migrate36 ~ $currentVersion 完成。新增迁移不需要改这个方法，详见文件头说明。
     */
    private static function buildFullSchema(): void
    {
        $pdo = Database::getInstance();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        $statements = [
            // V1: 迁移版本记录
            "CREATE TABLE IF NOT EXISTS `schema_versions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `version` int(11) unsigned NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移版本记录'",

            // V1+V2+V28: 用户表（含 token_version、cover）
            "CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码哈希',
  `email` varchar(100) NOT NULL COMMENT '邮箱',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) NOT NULL DEFAULT '/templates/moments/assets/images/default-avatar.svg' COMMENT '头像URL',
  `cover` varchar(500) NOT NULL DEFAULT '' COMMENT '用户封面URL（图片或视频）',
  `bio` text COMMENT '个人简介',
  `realname_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已通过实名认证',
  `realname_verified_at` datetime DEFAULT NULL COMMENT '实名认证通过时间',
  `role` enum('user','admin') NOT NULL DEFAULT 'user' COMMENT '角色',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `deleted_at` datetime DEFAULT NULL COMMENT '账号注销时间（NULL=正常；非NULL=已注销，内容保留并匿名化）',
  `token_version` int(11) unsigned NOT NULL DEFAULT '1' COMMENT 'Token版本号，用于强制失效',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  UNIQUE KEY `uk_users_nickname` (`nickname`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表'",

            // V1+V16: 分类表
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

            // V1+V7+V11+V14+V16+V19+V23+V24+V26: 动态表（最终态）
            "CREATE TABLE IF NOT EXISTS `posts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '作者ID',
  `category_id` int(11) unsigned DEFAULT NULL COMMENT '分类ID',
  `title` varchar(255) DEFAULT NULL COMMENT '动态标题（可选）',
  `content` text NOT NULL COMMENT '内容',
  `summary` varchar(300) DEFAULT NULL COMMENT 'AI 生成的内容摘要',
  `embedding` json DEFAULT NULL COMMENT '向量嵌入，用于语义搜索',
  `images` json DEFAULT NULL COMMENT '图片数组JSON',
  `videos` json DEFAULT NULL COMMENT '视频数组JSON（含url,cover,duration）',
  `attachments` json DEFAULT NULL COMMENT '附件数组JSON',
  `location` varchar(255) DEFAULT NULL COMMENT '定位地点名称',
  `location_lat` decimal(10,7) DEFAULT NULL COMMENT '定位纬度',
  `location_lng` decimal(10,7) DEFAULT NULL COMMENT '定位经度',
  `is_anonymous` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否匿名发布：0否 1是',
  `mentions` text DEFAULT NULL COMMENT '@提及用户列表（JSON）',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶: 0否 1是',
  `pinned_at` datetime DEFAULT NULL COMMENT '置顶时间',
  `circle_id` int(11) unsigned DEFAULT NULL COMMENT '所属圈子ID（NULL=全站动态，非NULL=仅圈子内可见）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_circle_id` (`circle_id`),
  KEY `idx_location` (`location`(191)),
  KEY `idx_posts_status_pinned_created` (`status`, `is_pinned`, `pinned_at`, `created_at`),
  KEY `idx_posts_user_status_pinned_created` (`user_id`, `status`, `is_pinned`, `pinned_at`, `created_at`),
  CONSTRAINT `fk_posts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态表'",

            // V1+V11: 点赞表（含覆盖索引）
            "CREATE TABLE IF NOT EXISTS `likes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_likes_post_created` (`post_id`, `created_at`),
  CONSTRAINT `fk_likes_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_likes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞表'",

            // V1+V11+V12+V22: 评论表（含 image、is_ai、覆盖索引）
            "CREATE TABLE IF NOT EXISTS `comments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `content` text NOT NULL,
  `is_ai` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为 AI 自动评论：0否 1是',
  `image` varchar(255) DEFAULT NULL COMMENT '评论图片URL',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_is_ai` (`is_ai`),
  KEY `idx_comments_post_created` (`post_id`, `created_at`),
  CONSTRAINT `fk_comments_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论表'",

            // V1: 系统设置表
            "CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL COMMENT '配置键',
  `value` text COMMENT '配置值',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表'",

            // V6+V18+V25: 通知表（type 最终为 varchar）
            "CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '接收者用户ID',
  `sender_id` int(11) unsigned NOT NULL COMMENT '触发者用户ID',
  `type` varchar(20) NOT NULL DEFAULT 'comment' COMMENT '通知类型',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息通知表'",

            // V7+V8/V12: 上传文件表（含 file_hash，V33 添加 video 枚举）
            "CREATE TABLE IF NOT EXISTS `uploads` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '上传用户ID',
  `post_id` int(11) unsigned DEFAULT NULL COMMENT '关联动态ID',
  `type` enum('image','attachment','video') NOT NULL DEFAULT 'image' COMMENT '类型',
  `filename` varchar(255) NOT NULL COMMENT '原始文件名',
  `url` varchar(255) NOT NULL COMMENT '访问URL',
  `size` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '文件大小（字节）',
  `file_hash` varchar(64) DEFAULT NULL COMMENT '文件哈希，用于秒传去重',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0下架 1正常',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_file_hash` (`file_hash`),
  CONSTRAINT `fk_uploads_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uploads_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上传文件表'",

            // V13: 友情链接表
            "CREATE TABLE IF NOT EXISTS `friend_links` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='友情链接表'",

            // V18: 关注关系表
            "CREATE TABLE IF NOT EXISTS `follows` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='关注关系表'",

            // V20: 动态收藏表
            "CREATE TABLE IF NOT EXISTS `favorites` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态收藏表'",

            // V21: 用户举报表
            "CREATE TABLE IF NOT EXISTS `reports` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户举报表'",

            // V23: 动态标签表
            "CREATE TABLE IF NOT EXISTS `post_tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `tag` varchar(30) NOT NULL COMMENT '标签名称',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_tag` (`tag`),
  CONSTRAINT `fk_post_tags_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态标签表'",

            // V24+V25: 用户行为日志表（action 最终为 varchar）
            "CREATE TABLE IF NOT EXISTS `user_actions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `action` varchar(20) NOT NULL COMMENT '行为类型',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户行为日志表'",

            // V26: @提及记录表
            "CREATE TABLE IF NOT EXISTS `mentions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='@提及记录表'",

            // V29: IM 会话表
            "CREATE TABLE IF NOT EXISTS `conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('private','group') NOT NULL DEFAULT 'private' COMMENT '会话类型',
  `title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '群聊标题（私聊为空）',
  `avatar` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '群聊头像',
  `creator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建者用户ID',
  `last_message_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '最后一条消息ID',
  `last_message_at` DATETIME DEFAULT NULL COMMENT '最后消息时间',
  `deleted_at` DATETIME DEFAULT NULL COMMENT '管理员下架时间（NULL=正常；会话行不物理删除）',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_last_message_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM会话表'",

            // V29: IM 会话成员表
            "CREATE TABLE IF NOT EXISTS `conversation_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('member','admin','owner') NOT NULL DEFAULT 'member' COMMENT '成员角色',
  `last_read_message_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '已读位置（该ID及之前的消息已读）',
  `muted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否免打扰',
  `pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶',
  `joined_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conv_user` (`conversation_id`, `user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM会话成员表'",

            // V29: IM 消息表
            "CREATE TABLE IF NOT EXISTS `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `content` MEDIUMTEXT NOT NULL COMMENT '消息内容',
  `type` ENUM('text','image','video','file','system') NOT NULL DEFAULT 'text' COMMENT '消息类型',
  `extra` JSON DEFAULT NULL COMMENT '附加数据（图片URL、文件信息等）',
  `created_at` DATETIME NOT NULL,
  `deleted_at` DATETIME DEFAULT NULL COMMENT '软删除时间（NULL=正常；消息永不物理删除，仅供审计与取证）',
  `deleted_by` INT UNSIGNED DEFAULT NULL COMMENT '删除操作人ID（用户ID或管理员ID）',
  `delete_scope` ENUM('self','admin','purge') DEFAULT NULL COMMENT 'self=发送者自己删除(仅自己不可见) admin=管理员下架(所有人不可见) purge=清理任务',
  `content_hash` CHAR(64) DEFAULT NULL COMMENT '内容指纹 sha256(加盐)，导出时校验是否被篡改',
  PRIMARY KEY (`id`),
  KEY `idx_conv_id_created` (`conversation_id`, `created_at`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM消息表'",

            // V32: 评论点赞表
            "CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_comment_user` (`comment_id`, `user_id`),
  KEY `idx_comment_id` (`comment_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞表'",

            // V32: 拉黑/屏蔽用户表
            "CREATE TABLE IF NOT EXISTS `blocked_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '发起拉黑的用户ID',
  `blocked_user_id` INT UNSIGNED NOT NULL COMMENT '被拉黑的用户ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_blocked` (`user_id`, `blocked_user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_blocked_user_id` (`blocked_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户拉黑屏蔽表'",

        // 【为什么这里没有 circles / app_versions / refresh_tokens …】
        // 这些是 V36 之后才有的表，由 run() 在本方法之后循环补跑 migrate36 ~ $currentVersion 建出。
        // 早年为了图省事把它们的建表语句也抄了一份进这里，结果同一张表有两处定义要同步维护
        // （改了 migrateNN 忘了改这里，就会让新装站点和老站点表结构不一致）。已清理，只保留一份。
        ];

        // P1-9：全量建表不再吞异常 —— 任何一张表 CREATE 失败都会抛给上层 run()，
        // 避免「18 张表建成、6 张表没建」的半残安装永久埋藏
        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                $msg = '[Hublog buildFullSchema] SQL 执行失败: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 300);
                error_log($msg);
                throw new \RuntimeException($msg, 0, $e);
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // V93：posts 全文索引（FULLTEXT）按数据库能力分级创建。
        //
        // 为什么不能写死在 CREATE TABLE 里：
        //   `WITH PARSER ngram` 是 MySQL 5.7+ 专有语法，MariaDB 不支持
        //   （MariaDB 没有 ngram 插件，也没有 WITH PARSER 语法）。
        //   以前它内联在 CREATE TABLE posts 里，MariaDB 上整条 CREATE 直接语法报错，
        //   posts 表建不出来 → 全新安装当场失败（不是「搜索降级」，是「装不上」）。
        //
        // 分级策略：
        //   1. 支持 ngram（MySQL）→ 建 WITH PARSER ngram，中文分词检索正常
        //   2. 不支持 ngram（MariaDB）→ 建普通 FULLTEXT（无 parser），英文词检索可用
        //   3. 连普通 FULLTEXT 都不行 → 不建索引，SearchController 自动退回 LIKE 匹配
        //
        // 无论走到哪一级都不能让安装失败，所以这里全程 try/catch 且不抛异常。
        self::ensurePostsFulltextIndex();

        self::seedFreshBase();
    }

    /**
     * 全新安装的「基础种子」：V1~V35 默认配置 + 虚拟 AI 用户。
     *
     * 从 buildFullSchema() 中原样抽出，供两条新装路径共用：
     *   - 老路径：buildFullSchema() 建基础表后调用；
     *   - 快速路径：runFreshInstall() 由终态 DDL 基线建完 85 张表后调用。
     * 种子数据只在这里维护一份，避免两条路径漂移。
     */
    private static function seedFreshBase(): void
    {
        // 插入默认配置（幂等）— 合并 V1-V30 所有设置项的最终值
        $defaults = [
            // V1+V9+V10: 站点基础
            'site_name' => '我圈',
            'site_desc' => '轻量级多用户社区博客系统',
            'site_icp' => '',
            'site_keywords' => '我圈,博客,社区,朋友圈',
            'site_description' => '轻量级多用户社区博客系统，分享生活点滴。',
            'site_stats_code' => '',
            // V1+V4: 用户权限
            'allow_register' => '1',
            'allow_post' => '1',
            'allow_like' => '1',
            'allow_comment' => '1',
            // V1+V5: 上传基础
            'upload_max_images' => '9',
            'upload_max_size' => '10',
            'upload_compress' => '1',
            'upload_max_width' => '1920',
            // V1+V9: 动态长度
            'post_max_length' => '2000',
            'post_collapse_length' => '300',
            // V7+V8: 附件与限流
            'upload_max_attachments' => '5',
            'upload_max_attachment_size' => '50',
            'upload_attachment_exts' => 'zip,rar,7z,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            'rate_limit_requests' => '1500',
            'rate_limit_window' => '60',
            'allow_guest_download_attachment' => '1',
            'upload_max_avatar_size' => '5',
            // V15: 标题预览
            'post_title_preview_length' => '150',
            // V17+V30: 伪静态默认关闭
            'title_required' => '0',
            'url_rewrite' => '0',
            // V22+V27: AI 评论（默认本地 Ollama）
            'ai_comment_enabled' => '0',
            'ai_comment_provider' => 'ollama',
            'ai_comment_api_url' => 'http://localhost:11434/v1/chat/completions',
            'ai_comment_api_key' => '',
            'ai_comment_model' => 'llama3.2',
            'ai_comment_prompt' => "你是一位热心的网友，正在浏览朋友圈。请根据下面这条动态，用 30-80 字发表一句真诚、有趣或带点幽默的评论。不要使用 emoji 以外的特殊字符，不要解释你在做什么，直接输出评论内容即可。",
            'ai_comment_max_length' => '200',
            'ai_comment_timeout' => '30',
            // V23: 内容审核 + 智能摘要
            'ai_audit_enabled' => '0',
            'ai_audit_provider' => 'ollama',
            'ai_audit_api_url' => 'http://localhost:11434/v1/chat/completions',
            'ai_audit_api_key' => '',
            'ai_audit_model' => 'llama3.2',
            'ai_audit_custom_words' => '',
            'smart_summary_enabled' => '0',
            'smart_summary_provider' => 'deepseek',
            'smart_summary_api_url' => 'https://api.deepseek.com/v1/chat/completions',
            'smart_summary_api_key' => '',
            'smart_summary_model' => 'deepseek-chat',
            // V24: 智能搜索 + 智能推荐
            'smart_search_enabled' => '0',
            'smart_search_provider' => 'deepseek',
            'smart_search_api_url' => 'https://api.deepseek.com/v1/embeddings',
            'smart_search_api_key' => '',
            'smart_search_model' => 'text-embedding-v1',
            'smart_recommend_enabled' => '0',
            'smart_recommend_max_items' => '20',
            // V26: @提及、定位、匿名
            'allow_mention' => '1',
            'allow_location' => '1',
            'allow_anonymous_post' => '1',
            'mention_max_count' => '10',
        ];

        // V43：图片水印默认值（与 Watermark::defaults() 保持单一数据源）
        $defaults = array_merge($defaults, Watermark::defaults());

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

        // 创建虚拟 AI 用户（V22+V27 逻辑，约定：管理员 id=1，AI 评论家 id=2）
        try {
            $exists = Database::fetchColumn("SELECT id FROM users WHERE username = 'ai_assistant' LIMIT 1");
            if (!$exists) {
                $id2Taken = Database::fetchColumn("SELECT id FROM users WHERE id = 2 LIMIT 1");
                if (!$id2Taken) {
                    // id=2 未被占用，显式指定 id=2（全新安装路径）
                    // ai_assistant 是虚拟机器人账号：密码随机生成后随即丢弃，全库无任何登录路径验证它，
                    // 用最低 cost 只保留合法的 bcrypt 字段格式，避免安装时浪费 239ms；真人账号仍走 Auth::hashPassword(cost12)。
                    Database::getInstance()->exec(
                        "INSERT INTO `users` (`id`, `username`, `password`, `email`, `nickname`, `avatar`, `bio`, `role`, `status`, `token_version`, `created_at`, `updated_at`) VALUES (2, 'ai_assistant', "
                        . Database::getInstance()->quote(password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 4]))
                        . ", 'ai_assistant@hublog.local', 'AI评论家', '/templates/moments/assets/images/default-avatar.svg', "
                        . Database::getInstance()->quote('我是 Hublog 内置的 AI 评论家，会自动对动态发表评论～')
                        . ", 'user', 1, 1, '" . self::now() . "', '" . self::now() . "')"
                    );
                } else {
                    // id=2 已被占用（重装场景），回退到自增
                    // 同上：机器人账号密码无人验证，最低 cost 保留合法 bcrypt 格式即可
                    Database::insert('users', [
                        'username' => 'ai_assistant',
                        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 4]),
                        'email' => 'ai_assistant@hublog.local',
                        'nickname' => 'AI评论家',
                        'avatar' => '/templates/moments/assets/images/default-avatar.svg',
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
            error_log('[Hublog buildFullSchema ai_assistant user] ' . $e->getMessage());
        }

        // 清理缓存，确保新配置生效
        try {
            Cache::delete('site_settings');
            Cache::delete('ai_comment_settings');
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    // ==================== 辅助方法 ====================

    /**
     * 统一的「当前时间」字符串（Y-m-d H:i:s）
     *
     * 迁移里写 created_at / updated_at 时用它，避免各处直接 date() 导致格式不一致。
     * 被 buildFullSchema() 与 migrate27/28/31/40 等共用 —— 改格式会影响全部迁移，别动。
     */
    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 迁移过程日志。
     *
     * 迁移脚本一旦静默失败，站点会出现「版本号没涨、表也没建出来」的无症状状态，
     * 所以每条迁移都必须往外吐一行日志。别改掉 —— 线上排查全靠它。
     */
    private static function log(string $message): void
    {
        error_log('[Hublog Migration] ' . $message);
    }
}