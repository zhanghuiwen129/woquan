<?php
/**
 * 迁移终态契约护栏（迁移正确性治理）
 *
 * 守住三件事：
 *   ① 契约必须与迁移源码一致 —— 新增表/列忘了跑生成器，CI 直接红（漏登的根因治理）；
 *   ② 版本回退、守卫内推进、终态自检这些修复不被后人改回旧写法；
 *   ③ 登记跳号与实际方法编号一致，避免 applyMigration() 在现存代码上误抛。
 *
 * 该测试不连接数据库。
 */

declare(strict_types=1);

use Hublog\Core\Migration;
use Hublog\Core\MigrationContract;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/MigrationContractScanner.php';

final class MigrationContractTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    /** ① 契约 = 源码抽取结果。这是「漏登」的终极护栏。 */
    public function testContractMatchesMigrationSource(): void
    {
        $scan = MigrationContractScanner::scan(self::root());

        $this->assertSame(
            $scan['tables'],
            MigrationContract::REQUIRED_TABLES,
            "REQUIRED_TABLES 与源码 CREATE TABLE 不一致，请执行 php scripts/build_migration_contract.php --write\n"
            . '新增表: ' . implode(', ', array_diff($scan['tables'], MigrationContract::REQUIRED_TABLES))
        );
        $this->assertSame(
            $scan['columnNames'],
            MigrationContract::REQUIRED_COLUMN_NAMES,
            "REQUIRED_COLUMN_NAMES 与源码 ADD COLUMN 不一致，请重新生成契约\n"
            . '新增列: ' . implode(', ', array_diff($scan['columnNames'], MigrationContract::REQUIRED_COLUMN_NAMES))
        );
    }

    /** 曾被漏登的 4 张表，现在必须在终态契约里。 */
    public function testPreviouslyMissingTablesAreCovered(): void
    {
        foreach (['app_crash_logs', 'question_answers', 'wheel_prizes', 'wheel_records'] as $table) {
            $this->assertContains($table, MigrationContract::REQUIRED_TABLES, "漏登记表 {$table} 必须在契约中");
        }
    }

    public function testContractHasMeaningfulCoverage(): void
    {
        $this->assertGreaterThanOrEqual(75, count(MigrationContract::REQUIRED_TABLES), '表清单应覆盖全部业务表');
        $this->assertGreaterThanOrEqual(70, count(MigrationContract::REQUIRED_COLUMN_NAMES), '增量列清单不能只留十几个');
        $this->assertGreaterThanOrEqual(16, count(MigrationContract::CRITICAL_COLUMNS), '精确哨兵列不可被删减');
        $this->assertGreaterThanOrEqual(4, count(MigrationContract::REQUIRED_UNIQUE_INDEXES), '幂等唯一键不可被删减');
    }

    /**
     * ① 契约登记的幂等唯一键必须真实存在于迁移源码。
     *
     * 登记了源码里根本没有的索引名 = 幽灵键：数据库里永远不会出现它，
     * 巡检脚本会永远报「缺失」，把真问题淹没在假告警里。
     */
    public function testRegisteredUniqueIndexesExistInMigrationSource(): void
    {
        $scan = MigrationContractScanner::scan(self::root());
        $sourceNames = array_map('strtolower', $scan['uniqueIndexNames']);

        $registered = [];
        foreach (MigrationContract::REQUIRED_UNIQUE_INDEXES as $spec) {
            $registered[] = strtolower($spec['index']);
        }

        $ghost = array_values(array_diff(array_unique($registered), $sourceNames));
        $this->assertSame(
            [],
            $ghost,
            "契约登记了迁移源码里不存在的唯一索引:\n"
            . implode("\n", $ghost)
            . "\n请修正 REQUIRED_UNIQUE_INDEXES 或补上对应迁移。"
        );
    }

    /**
     * P0-1：资金链路的四把闸必须都在契约里。
     *
     * 这四个键任缺其一，重复支付回调 / 订单重放就能重复入账或重复发券，
     * 而「表 / 列」维度的终态校验完全查不出来。
     */
    public function testMoneyIdempotencyKeysAreRegistered(): void
    {
        $keys = [];
        foreach (MigrationContract::REQUIRED_UNIQUE_INDEXES as $spec) {
            $keys[] = $spec['table'] . '.' . $spec['index'];
        }

        foreach ([
            'wallet_logs.uk_wallet_biz_key',
            'recharge_orders.uk_trade_no',
            'user_coupons.uk_coupon_order_slot',
            'post_tags.uk_post_tag',
        ] as $mustHave) {
            $this->assertContains($mustHave, $keys, "幂等唯一键 {$mustHave} 必须登记进契约");
        }
    }

    /** 每个登记项都要写清「为什么需要它」——否则后人无从判断能不能删。 */
    public function testEveryUniqueIndexDocumentsItsPurpose(): void
    {
        foreach (MigrationContract::REQUIRED_UNIQUE_INDEXES as $spec) {
            $this->assertArrayHasKey('table', $spec);
            $this->assertArrayHasKey('index', $spec);
            $this->assertArrayHasKey('cols', $spec);
            $this->assertArrayHasKey('why', $spec);
            $this->assertNotSame('', trim((string)$spec['why']), "{$spec['table']}.{$spec['index']} 必须写清用途");
            $this->assertNotEmpty($spec['cols'], "{$spec['table']}.{$spec['index']} 必须写清索引列");
        }
    }

    /** ② 幂等键缺失必须在契约 diff 里可见（表列都在也必须报出来）。 */
    public function testContractDiffExposesMissingUniqueIndexDimension(): void
    {
        $src = self::source('api/core/MigrationContract.php');
        $this->assertStringContainsString('REQUIRED_UNIQUE_INDEXES', $src, '契约必须声明幂等唯一键维度');
        $this->assertStringContainsString("'uniqueIndexes' =>", $src, 'diff() 必须返回幂等唯一键维度');
        $this->assertStringContainsString('isFullyComplete', $src, '必须提供「结构 + 唯一键都满足」的判定');
    }

    /** ② 幂等键缺失不能阻断存量站点升级（否则会陷入回退循环），但必须被发现并补建。 */
    public function testMigrationRepairsIdempotencyIndexesWithoutBlockingUpgrade(): void
    {
        $src = self::source('api/core/Migration.php');
        $this->assertStringContainsString('ensureIdempotencyIndexes(', $src, '必须实现幂等键补建');
        $this->assertStringContainsString('information_schema.STATISTICS', $src, '必须真实查询唯一索引现状');
        $this->assertStringContainsString('ADD UNIQUE KEY', $src, '补建必须走唯一索引 DDL');
        $this->assertStringContainsString('writeIdempotencyMarker(', $src, '补建失败要落可巡检标记');
        $this->assertStringContainsString('getIdempotencyStatus(', $src, '要提供运维读取入口');
    }

    /** ③ 登记跳号白名单与真实方法编号必须一致。 */
    public function testIntentionalGapsMatchRealMethodNumbers(): void
    {
        $r = new ReflectionClass(Migration::class);
        $p = $r->getProperty('currentVersion');
        $p->setAccessible(true);
        $max = (int)$p->getValue();

        $gaps = MigrationContract::INTENTIONAL_GAPS;
        $this->assertSame($gaps, array_values(array_unique($gaps)), '跳号白名单不可重复');
        $sorted = $gaps;
        sort($sorted);
        $this->assertSame($sorted, $gaps, '跳号白名单必须升序（测试与生成器按此比对）');

        $unexpected = [];
        for ($v = 1; $v <= $max; $v++) {
            $has = $r->hasMethod("migrate{$v}");
            $registered = in_array($v, $gaps, true);
            if ($has === $registered) {
                // 有方法却登记成跳号、或没方法却没登记，都是不一致
                $unexpected[] = $v . ($has ? '(有方法却被登记为跳号)' : '(无方法且未登记跳号)');
            }
        }
        $this->assertEmpty(
            $unexpected,
            "applyMigration() 会按白名单放行、按缺失抛异常，白名单必须与实际方法严格对应:\n"
            . implode("\n", $unexpected)
        );
    }

    /** ② 自检清单不再手工维护在 Migration.php 里。 */
    public function testMigrationUsesContractInsteadOfHandMaintainedChecklist(): void
    {
        $src = self::source('api/core/Migration.php');
        $this->assertStringContainsString('MigrationContract::REQUIRED_TABLES', $src, '必须使用生成的契约清单');
        $this->assertStringContainsString('MigrationContract::diff(', $src, '必须走契约 diff');
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+criticalTablesExist\s*\(/',
            $src,
            '旧的手工清单方法应已移除'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+criticalColumnsExist\s*\(/',
            $src,
            '旧的手工清单方法应已移除'
        );
        $this->assertStringNotContainsString('$requiredTables = [', $src, '不得再出现硬编码表清单');
        $this->assertStringNotContainsString('$requiredColumns = [', $src, '不得再出现硬编码列清单');
    }

    /** ② DB 版本号必须能真正下降，且写失败要抛而不是吞。 */
    public function testSaveVersionReallyRollsBack(): void
    {
        $src = self::source('api/core/Migration.php');
        $this->assertStringContainsString(
            'DELETE FROM schema_versions WHERE version >= ?',
            $src,
            'saveVersion() 必须删除 >= 目标版本的 DB 记录，否则 MAX(version) 永远不会下降'
        );
        $this->assertStringContainsString('INSERT INTO schema_versions (version, applied_at)', $src);
        $this->assertStringNotContainsString(
            'INSERT IGNORE INTO schema_versions',
            $src,
            '不应再依赖 MySQL 专有语法（注释里提到旧实现不算）'
        );
        $this->assertStringContainsString(
            'schema_versions 写入失败，迁移中止',
            $src,
            'DB 写失败必须抛异常，静默降级会让多机部署下的回退语义失效'
        );
    }

    /** ② 版本推进必须在守卫内，且跑完要做终态契约自检。 */
    public function testRunGuardsVersionAdvanceAndVerifiesFinalState(): void
    {
        $src = self::source('api/core/Migration.php');
        $this->assertStringContainsString('self::applyMigration($v);', $src);
        $this->assertStringContainsString('$diff = self::schemaDiff();', $src);
        $this->assertStringContainsString('self::saveVersion($upgradeFrom);', $src, '终态不满足必须回退到升级前版本');
        $this->assertStringContainsString('全新安装后终态契约未满足', $src);
        $this->assertStringContainsString('升级后终态契约未满足', $src);
        $this->assertSame(
            2,
            substr_count($src, 'if (!MigrationContract::isComplete($diff)) {'),
            '全新安装与升级两条路径都必须真正执行终态契约校验，不能留成永不生效的死分支'
        );
        $this->assertStringContainsString('writeFailureMarker(', $src, '生产失败要落可巡检标记');
        $this->assertStringContainsString('clearFailureMarker(', $src, '成功后要清除失败标记');
    }

    /** ② 安装必须同时清掉 .schema_verified（旧实现只删 .schema_version）。 */
    public function testInstallResetsAllLocalMigrationState(): void
    {
        $src = self::source('install.php');
        $this->assertStringContainsString('Migration::resetState()', $src);
        $this->assertStringNotContainsString("@unlink(__DIR__ . '/cache/.schema_version')", $src);
    }
}
