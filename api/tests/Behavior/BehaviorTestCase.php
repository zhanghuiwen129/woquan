<?php
/**
 * 行为级测试底座（阶段 1：把「读源码文本断言」换成「真实调用 + 验证行为」）
 *
 * 为什么用 SQLite :memory: 而不是连真实 MySQL：
 *   1. 零配置、零凭据 —— CI 里不需要准备数据库服务，clone 下来就能跑完整行为测试；
 *   2. 物理上不可能误伤生产库 —— 内存库没有 host/user/password，Database::getInstance()
 *      拿到的就是我们反射注入的 PDO，永远不会走到 config.php 里的 DSN 分支；
 *   3. 每个用例一个全新内存库，用例之间天然隔离，不需要事务回滚或 truncate 顺序约定。
 *
 * 与现有两套测试的关系：
 *   unit         结构性断言（源码文本 / 反射），保留但逐步被行为断言替代；
 *   behavior     本目录，真实调用业务类，CI 默认门禁执行；
 *   integration  MigrationDatabaseTest，需要真实 MySQL + HUBLOG_DB_TEST=1 + _test 库名双护栏。
 *
 * 全局状态隔离：Database::$instance 与 Cache::$cacheDir 都是 private static，
 * 通过反射替换并在 tearDown 严格还原，保证不污染同进程里的其它套件。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Cache;
use Hublog\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

abstract class BehaviorTestCase extends TestCase
{
    /** 持有连接，防止 :memory: PDO 在用例执行期间被 GC 回收导致库消失 */
    private ?PDO $pdo = null;

    private static bool $captured = false;
    private static mixed $originalDbInstance = null;
    private static ?string $originalCacheDir = null;
    private static mixed $originalRedis = null;
    private static ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::captureGlobals();
        self::prepareCacheDir();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->createSchema($pdo);
        $this->pdo = $pdo;

        $this->setStatic(Database::class, 'instance', $pdo);
        $this->setStatic(Cache::class, 'redis', null);
        Cache::flush();

        // 让所有读取 settings 的业务都落在「未配置」分支：
        // sms_provider=none 会让 Sms::send() 直接返回 false，绝不发起外部网络请求。
        $pdo->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('sms_provider', 'none')");
    }

    protected function tearDown(): void
    {
        // 严格还原全局状态：unit / integration 套件可能与本套件跑在同一个 PHP 进程里。
        $this->setStatic(Database::class, 'instance', self::$originalDbInstance);
        $this->setStatic(Cache::class, 'cacheDir', self::$originalCacheDir);
        $this->setStatic(Cache::class, 'redis', self::$originalRedis);
        $this->pdo = null;

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tempDir !== null && is_dir(self::$tempDir)) {
            foreach (glob(self::$tempDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir(self::$tempDir);
            self::$tempDir = null;
        }
        parent::tearDownAfterClass();
    }

    // ── 全局状态捕获 / 隔离 ───────────────────────────────────────────────

    private static function captureGlobals(): void
    {
        if (self::$captured) {
            return;
        }
        self::$originalDbInstance = self::readStatic(Database::class, 'instance');
        self::$originalCacheDir   = self::readStatic(Cache::class, 'cacheDir');
        self::$originalRedis      = self::readStatic(Cache::class, 'redis');
        self::$captured = true;
    }

    private static function prepareCacheDir(): void
    {
        if (self::$tempDir === null || !is_dir(self::$tempDir)) {
            self::$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'hublog_behavior_' . bin2hex(random_bytes(6));
            @mkdir(self::$tempDir, 0777, true);
        }
        self::writeStatic(Cache::class, 'cacheDir', self::$tempDir . DIRECTORY_SEPARATOR);
    }

    private static function readStatic(string $class, string $property): mixed
    {
        $ref = new ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        return $ref->getValue();
    }

    private static function writeStatic(string $class, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }

    /** 实例方法包装，供 setUp/tearDown 调用 */
    private function setStatic(string $class, string $property, mixed $value): void
    {
        self::writeStatic($class, $property, $value);
    }

    // ── 测试库 ────────────────────────────────────────────────────────────

    protected function pdo(): PDO
    {
        $this->assertNotNull($this->pdo, '行为测试底座未初始化');
        return $this->pdo;
    }

    /** 最小 schema：只建被测业务真正会查询的表，保持 SQLite / MySQL 兼容语法 */
    private function createSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE `phone_codes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `phone`      TEXT    NOT NULL,
    `code`       TEXT    NOT NULL,
    `scene`      TEXT    NOT NULL,
    `expires_at` TEXT    NOT NULL,
    `sent_ip`    TEXT    DEFAULT NULL,
    `used_at`    TEXT    DEFAULT NULL,
    `created_at` TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE `settings` (
    `key`   TEXT PRIMARY KEY,
    `value` TEXT DEFAULT NULL
);
CREATE TABLE `uploads` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `file_hash` TEXT    NOT NULL,
    `url`       TEXT    DEFAULT NULL,
    `filename`  TEXT    DEFAULT NULL,
    `type`      TEXT    DEFAULT 'attachment',
    `status`    INTEGER NOT NULL DEFAULT 1
);
-- 列齐 Auth::check() 的真实 SELECT（SQLite 遇到缺失列会直接报错），
-- 这样 UserController::sendPhoneCode 里的 Auth::check 也能在行为测试里真实跑通。
CREATE TABLE `users` (
    `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
    `username`       TEXT    DEFAULT NULL,
    `nickname`       TEXT    DEFAULT NULL,
    `avatar`         TEXT    DEFAULT NULL,
    `cover`          TEXT    DEFAULT NULL,
    `email`          TEXT    DEFAULT NULL,
    `bio`            TEXT    DEFAULT NULL,
    `role`           TEXT    DEFAULT 'user',
    `status`         INTEGER NOT NULL DEFAULT 1,
    `token_version`  INTEGER NOT NULL DEFAULT 1,
    `created_at`     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    `vip_level`      INTEGER NOT NULL DEFAULT 0,
    `vip_expire_at`  TEXT    DEFAULT NULL,
    `balance`        REAL    NOT NULL DEFAULT 0,
    `points`         INTEGER NOT NULL DEFAULT 0,
    `avatar_frame`   TEXT    DEFAULT NULL,
    `muted_until`    TEXT    DEFAULT NULL,
    `phone`          TEXT    DEFAULT NULL
);
CREATE TABLE `rate_limits` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `ip`      TEXT    NOT NULL,
    `action`  TEXT    NOT NULL,
    `created_at` TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
SQL);
    }

    /** 取表全部行，用于断言真实的写库副作用 */
    protected function rows(string $table): array
    {
        $this->assertTrue(
            (bool)preg_match('/^[a-zA-Z0-9_]+$/', $table),
            '表名必须是简单标识符'
        );
        return $this->pdo()->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function seed(string $table, array $row): void
    {
        $cols = array_keys($row);
        foreach ($cols as $col) {
            $this->assertTrue((bool)preg_match('/^[a-zA-Z0-9_]+$/', (string)$col), '非法字段名');
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->pdo()->prepare($sql)->execute(array_values($row));
    }

    // ── 隔离子进程（用于需要不同常量取值的分支） ──────────────────────────

    /**
     * 在独立 PHP 进程里执行 fixture 脚本并返回其 JSON 输出。
     *
     * 用途：APP_DEBUG 这类由 bootstrap 定义的常量在进程内不可更改，
     * 需要测 DEBUG=true 分支时只能在子进程里先 define 再 require bootstrap。
     *
     * @param string $fixture 相对 api/tests/Behavior/fixtures/ 的文件名
     * @param array<string,string> $env 额外环境变量
     */
    protected function runIsolated(string $fixture, array $env = []): array
    {
        [$exit, $output] = $this->runIsolatedRaw($fixture, $env);
        $this->assertSame(0, $exit, "子进程执行失败 ({$fixture}): " . trim($output));

        $line = '';
        foreach (array_reverse(explode("\n", trim($output))) as $candidate) {
            if (str_starts_with(trim($candidate), '{')) {
                $line = trim($candidate);
                break;
            }
        }
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "fixture 未输出合法 JSON ({$fixture}): " . trim($output));
        return $decoded;
    }

    /** @return array{0:int,1:string} [退出码, stdout+stderr 原始输出] */
    protected function runIsolatedRaw(string $fixture, array $env = []): array
    {
        $script = __DIR__ . '/fixtures/' . $fixture;
        $this->assertFileExists($script, "缺少 fixture: {$fixture}");

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        $output = '';
        $exit = 127;

        if (function_exists('proc_open')) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            // proc_open 的 env 是「替换」而非「合并」，Windows 下丢掉 SystemRoot
            // 会让子进程起不来，所以先继承全部再覆盖指定项。
            $inherited = getenv() ?: [];
            foreach ($env as $k => $v) {
                $inherited[$k] = $v;
            }
            $pipes = [];
            $proc = @proc_open($cmd, $descriptors, $pipes, null, $inherited);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);
                $output = (string)$stdout;
                if ($stderr !== '' && trim($output) === '') {
                    $output = (string)$stderr;
                }
            }
        }
        if ($exit === 127 && function_exists('exec')) {
            $lines = [];
            $prefix = '';
            foreach ($env as $k => $v) {
                $prefix .= $k . '=' . escapeshellarg($v) . ' ';
            }
            exec($prefix . $cmd . ' 2>&1', $lines, $exit);
            $output = implode("\n", $lines);
        }

        return [$exit, $output];
    }

    /** 项目根目录绝对路径 */
    protected static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
