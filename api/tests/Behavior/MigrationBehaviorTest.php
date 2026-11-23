<?php
/**
 * 迁移正确性治理的行为级验证。
 *
 * 与 MigrationContractTest（源码/结构断言）互补：这里真实调用 MigrationContract::diff()、
 * Migration::applyMigration() / resetState() / markVerified() / writeFailureMarker()，
 * 并验证真实的文件副作用与异常行为。
 *
 * 不连接任何数据库：SQLite 内存库由 BehaviorTestCase 注入，但本组用例只用纯逻辑 + 临时目录。
 * 静态文件路径在 setUp 反射改到临时目录，tearDown 严格还原，绝不碰仓库的 cache/。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Migration;
use Hublog\Core\MigrationContract;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class MigrationBehaviorTest extends BehaviorTestCase
{
    private string $tempDir = '';

    /** @var array<string,string> */
    private array $originalPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_migration_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0777, true);

        foreach (['versionFile', 'verifiedFile', 'failureFile', 'idempotencyFile'] as $property) {
            $ref = new ReflectionProperty(Migration::class, $property);
            $ref->setAccessible(true);
            $this->originalPaths[$property] = (string)$ref->getValue();
            $ref->setValue(null, $this->tempDir . DIRECTORY_SEPARATOR . $property);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalPaths as $property => $value) {
            $ref = new ReflectionProperty(Migration::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, $value);
        }
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    // ── 契约 diff 真实行为 ────────────────────────────────────────────────

    /** @return array{0:array<string,bool>,1:array<string,bool>,2:array<string,bool>} */
    private function completeSchema(): array
    {
        $tables = array_fill_keys(MigrationContract::REQUIRED_TABLES, true);
        $names = array_fill_keys(MigrationContract::REQUIRED_COLUMN_NAMES, true);
        $keys = [];
        foreach (MigrationContract::CRITICAL_COLUMNS as [$table, $column]) {
            $keys[$table . '.' . $column] = true;
        }
        return [$tables, $names, $keys];
    }

    public function testCompleteSchemaPassesContract(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        $diff = MigrationContract::diff($tables, $names, $keys);
        $this->assertTrue(MigrationContract::isComplete($diff), MigrationContract::describe($diff));
    }

    public function testMissingTableIsDetected(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        unset($tables['wheel_prizes']);

        $diff = MigrationContract::diff($tables, $names, $keys);
        $this->assertContains('wheel_prizes', $diff['tables']);
        $this->assertFalse(MigrationContract::isComplete($diff));
        $this->assertStringContainsString('wheel_prizes', MigrationContract::describe($diff));
    }

    public function testMissingColumnNameIsDetected(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        unset($names['declaration']); // 模拟 ADD COLUMN 被 catch 吞掉

        $diff = MigrationContract::diff($tables, $names, $keys);
        $this->assertContains('declaration', $diff['columnNames']);
        $this->assertFalse(MigrationContract::isComplete($diff));
    }

    /** 列建到了别的表上时，只有精确「表.列」哨兵能发现 —— 验证两层校验都必要。 */
    public function testCriticalColumnOnWrongTableIsDetected(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        unset($keys['posts.declaration']); // 同名列仍在别的表中，列名层校验会通过

        $diff = MigrationContract::diff($tables, $names, $keys);
        $this->assertSame([], $diff['columnNames'], '列名层看不出「建错表」');
        $this->assertContains('posts.declaration', $diff['columns'], '精确哨兵必须拦住');
        $this->assertFalse(MigrationContract::isComplete($diff));
    }

    // ── 幂等唯一键维度（P0-1） ────────────────────────────────────────────

    /**
     * 表和列都在、唯一键没建出来 —— 这是 migrate92 被 catch 吞掉时的真实形态。
     * 前三个维度查不出来，第四个维度必须报出来。
     */
    public function testMissingUniqueIndexIsDetectedEvenWhenTablesAndColumnsAreComplete(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        $indexes = array_fill_keys(array_map(
            static fn(array $s): string => $s['table'] . '.' . $s['index'],
            MigrationContract::REQUIRED_UNIQUE_INDEXES
        ), true);

        unset($indexes['wallet_logs.uk_wallet_biz_key']);

        $diff = MigrationContract::diff($tables, $names, $keys, $indexes);

        $this->assertSame([], $diff['tables'], '表维度不该有差异');
        $this->assertSame([], $diff['columns'], '列维度不该有差异');
        $this->assertContains(
            'wallet_logs.uk_wallet_biz_key',
            $diff['uniqueIndexes'],
            '唯一键没建出来必须被契约发现'
        );
        $this->assertStringContainsString(
            'wallet_logs.uk_wallet_biz_key',
            MigrationContract::describe($diff),
            'describe() 必须报出缺失的幂等键'
        );
    }

    /**
     * 结构齐但幂等键缺 —— 不能阻断升级。
     *
     * 阻断会让站点在「历史数据必然重复、补建必然失败」的表上陷入
     * 「回退 → 重跑迁移 → 再失败 → 再回退」的无限循环，整站直接不可用。
     */
    public function testMissingUniqueIndexDoesNotBlockUpgradeButIsReported(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        $diff = MigrationContract::diff($tables, $names, $keys, []);

        $this->assertTrue(
            MigrationContract::isComplete($diff),
            '唯一键缺失不得让结构维度的升级门禁失败'
        );
        $this->assertFalse(
            MigrationContract::isFullyComplete($diff),
            '全新安装路径必须能识别出唯一键没就位'
        );
        $this->assertNotEmpty($diff['uniqueIndexes']);
    }

    /** 全部唯一键都在时，两个判定都为真。 */
    public function testFullyCompleteWhenEveryUniqueIndexExists(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        $indexes = array_fill_keys(array_map(
            static fn(array $s): string => $s['table'] . '.' . $s['index'],
            MigrationContract::REQUIRED_UNIQUE_INDEXES
        ), true);

        $diff = MigrationContract::diff($tables, $names, $keys, $indexes);
        $this->assertTrue(MigrationContract::isComplete($diff));
        $this->assertTrue(MigrationContract::isFullyComplete($diff));
    }

    /** 表整体缺失时，不再重复报「这张表上的唯一键也缺」——避免告警噪音。 */
    public function testMissingTableSuppressesItsUniqueIndexReport(): void
    {
        [$tables, $names, $keys] = $this->completeSchema();
        unset($tables['wallet_logs']);

        $diff = MigrationContract::diff($tables, $names, $keys, []);
        $this->assertContains('wallet_logs', $diff['tables']);
        $this->assertNotContains(
            'wallet_logs.uk_wallet_biz_key',
            $diff['uniqueIndexes'],
            '表都没了，唯一键必然也没有，不必重复报'
        );
    }

    /**
     * 补建失败必须落可巡检标记，且失败不能把异常抛出去打断站点。
     *
     * SQLite 不支持 MySQL 的 ADD UNIQUE KEY 语法，正好模拟「补建失败」这条路径。
     */
    public function testIdempotencyRepairFailureIsRecordedNotThrown(): void
    {
        $path = $this->readPrivate('idempotencyFile');
        @unlink($path);

        $diff = [
            'tables' => [],
            'columnNames' => [],
            'columns' => [],
            'uniqueIndexes' => ['wallet_logs.uk_wallet_biz_key'],
        ];

        $stillMissing = $this->invokePrivate('ensureIdempotencyIndexes', [$diff]);

        $this->assertSame(
            ['wallet_logs.uk_wallet_biz_key'],
            $stillMissing,
            '补建失败必须如实报告仍然缺失'
        );
        $this->assertFileExists($path, '补建失败要落可巡检标记文件');

        $status = Migration::getIdempotencyStatus();
        $this->assertIsArray($status);
        $this->assertContains('wallet_logs.uk_wallet_biz_key', $status['missing']);
        $this->assertArrayHasKey('wallet_logs.uk_wallet_biz_key', $status['errors']);
        $this->assertGreaterThan(time(), $status['missing'] === [] ? 0 : (int)(
            json_decode((string)file_get_contents($path), true)['retry_after'] ?? 0
        ), '失败项必须带退避时间，避免每轮校验都重复尝试');
    }

    /** 幂等键全部就位时，巡检标记必须被清掉。 */
    public function testIdempotencyMarkerIsClearedWhenNothingMissing(): void
    {
        $path = $this->readPrivate('idempotencyFile');
        file_put_contents($path, '{"time":"x","missing":["a"],"errors":{"a":"boom"},"retry_after":1}');
        $this->assertFileExists($path);

        $diff = ['tables' => [], 'columnNames' => [], 'columns' => [], 'uniqueIndexes' => []];
        $this->assertSame([], $this->invokePrivate('ensureIdempotencyIndexes', [$diff]));
        $this->assertFileDoesNotExist($path, '全部就位后必须清掉旧标记');
        $this->assertNull(Migration::getIdempotencyStatus());
    }

    // ── applyMigration：误删方法不再静默推进版本号 ─────────────────────────

    public function testUnregisteredMissingMethodThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrate999');
        $this->invokePrivate('applyMigration', [999]);
    }

    public function testRegisteredGapIsAllowed(): void
    {
        foreach (MigrationContract::INTENTIONAL_GAPS as $gap) {
            $this->invokePrivate('applyMigration', [$gap]);
        }
        $this->assertTrue(true, '登记跳号不应触发异常，也不应执行任何 DDL');
    }

    // ── DB 版本号真回退（原实现只写文件、DB MAX(version) 永远不降） ─────────

    public function testSaveVersionReallyLowersDbVersion(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE `schema_versions` (`version` INTEGER NOT NULL UNIQUE, `applied_at` TEXT NOT NULL)');
        $pdo->exec("INSERT INTO `schema_versions` VALUES (90, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO `schema_versions` VALUES (91, '2026-01-02 00:00:00')");

        $this->invokePrivate('saveVersion', [25]);

        $versions = $pdo->query('SELECT `version` FROM `schema_versions` ORDER BY `version`')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(
            [25],
            array_map('intval', $versions),
            '回退后 DB 里不能残留 90/91，否则多机部署下别的机器仍认为已升级到最终版本'
        );
    }

    /** 版本表被误删时不能让「自检失败 → 回退 → 重跑」链路卡死在第一步 */
    public function testSaveVersionRecoversWhenVersionTableIsMissing(): void
    {
        $this->invokePrivate('saveVersion', [25]);

        $version = (int)$this->pdo()->query('SELECT `version` FROM `schema_versions`')->fetchColumn();
        $this->assertSame(25, $version, 'schema_versions 缺失时应自动补建并写入目标版本');
    }

    public function testSaveVersionIsIdempotent(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE `schema_versions` (`version` INTEGER NOT NULL UNIQUE, `applied_at` TEXT NOT NULL)');

        $this->invokePrivate('saveVersion', [25]);
        $this->invokePrivate('saveVersion', [25]);

        $count = (int)$pdo->query('SELECT COUNT(*) FROM `schema_versions`')->fetchColumn();
        $this->assertSame(1, $count, '去掉 INSERT IGNORE 后仍必须幂等');
    }

    // ── 本地状态与失败标记的真实文件副作用 ─────────────────────────────────

    public function testResetStateRemovesAllThreeMarkers(): void
    {
        $files = [];
        foreach (['versionFile', 'verifiedFile', 'failureFile'] as $property) {
            $path = $this->readPrivate($property);
            file_put_contents($path, 'stale');
            $this->assertFileExists($path);
            $files[] = $path;
        }

        Migration::resetState();

        foreach ($files as $path) {
            $this->assertFileDoesNotExist($path, 'install.php 走全新安装前必须清掉全部本地标记');
        }
    }

    /** 版本回退后，稳态快路径必须失效（否则缺表永远不会被重跑发现）。 */
    public function testRollbackInvalidatesVerificationCache(): void
    {
        $current = (int)$this->readPrivate('currentVersion');

        $this->invokePrivate('markVerified', [$current]);
        $this->assertTrue((bool)$this->invokePrivate('verificationCacheFresh'));

        $this->invokePrivate('markVerified', [0]);
        $this->assertFalse(
            (bool)$this->invokePrivate('verificationCacheFresh'),
            'saveVersion(0) 同写的 markVerified(0) 必须让下一请求回到完整校验路径'
        );
    }

    public function testFailureMarkerIsWrittenAndCleared(): void
    {
        $path = $this->readPrivate('failureFile');

        $this->invokePrivate('writeFailureMarker', ['boom: ALTER TABLE 被吞']);
        $this->assertFileExists($path);
        $payload = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($payload);
        $this->assertSame('boom: ALTER TABLE 被吞', $payload['error']);
        $this->assertSame((int)$this->readPrivate('currentVersion'), $payload['target_version']);
        $this->assertArrayHasKey('time', $payload);

        $this->invokePrivate('clearFailureMarker');
        $this->assertFileDoesNotExist($path);
    }

    // ── 反射工具 ─────────────────────────────────────────────────────────

    private function invokePrivate(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(Migration::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    private function readPrivate(string $property): mixed
    {
        $ref = new ReflectionProperty(Migration::class, $property);
        $ref->setAccessible(true);
        return $ref->getValue();
    }
}
