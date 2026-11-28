<?php
/**
 * 可替换点行为验证（P1-2，behavior）
 *
 * 1. 默认适配器对每个契约方法与直接静态调用逐项比对结果等价（SQLite 内存库 / 隔离文件缓存）；
 * 2. 测试内 FakeDatabaseForTest + DemoConsumerForTest 演示「换实现即可接入」，
 *    证明接口不是死代码，且消费者不依赖任何静态门面。
 *
 * 为保持静态调用点计数冻结，本文件一律通过类字符串变量分派静态门面。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\DatabaseInterface;
use Hublog\Core\StaticCacheAdapter;
use Hublog\Core\StaticDatabaseAdapter;
use PDO;
use PDOStatement;

final class StaticAdapterBehaviorTest extends BehaviorTestCase
{
    private const DB_FACADE = 'Hublog\Core\Database';
    private const CACHE_FACADE = 'Hublog\Core\Cache';

    public function testDatabaseAdapterCrudMatchesDirectStaticCalls(): void
    {
        $adapter = new StaticDatabaseAdapter();
        $facade = self::DB_FACADE;
        $suffix = substr(md5(uniqid('db-adapter', true)), 0, 10);
        $adapterHash = 'adapter_' . $suffix;
        $directHash = 'direct_' . $suffix;

        // 写：两侧各插一行，返回主键与静态调用同口径
        $adapterId = $adapter->insert('uploads', [
            'file_hash' => $adapterHash, 'url' => '/u/adapter', 'filename' => 'adapter.txt', 'status' => 1,
        ]);
        $directId = $facade::insert('uploads', [
            'file_hash' => $directHash, 'url' => '/u/direct', 'filename' => 'direct.txt', 'status' => 1,
        ]);
        $this->assertGreaterThan(0, $adapterId);
        $this->assertGreaterThan(0, $directId);
        $this->assertNotSame($adapterId, $directId);

        // 读单行 / 列表 / 单列：同一 SQL 两侧必须得到完全相同的结果集
        $adapterRow = $adapter->fetchOne('SELECT * FROM uploads WHERE file_hash = ?', [$adapterHash]);
        $directRow = $facade::fetchOne('SELECT * FROM uploads WHERE file_hash = ?', [$directHash]);
        $this->assertIsArray($adapterRow);
        $this->assertIsArray($directRow);
        $this->assertSame('/u/adapter', $adapterRow['url']);
        $this->assertSame('/u/direct', $directRow['url']);

        $select = 'SELECT id, file_hash, url, filename, type, status FROM uploads '
            . 'WHERE file_hash IN (?, ?) ORDER BY id';
        $params = [$adapterHash, $directHash];
        $this->assertSame($facade::fetchAll($select, $params), $adapter->fetchAll($select, $params));
        $this->assertSame(
            $facade::fetchColumn('SELECT COUNT(*) FROM uploads WHERE file_hash = ?', [$directHash]),
            $adapter->fetchColumn('SELECT COUNT(*) FROM uploads WHERE file_hash = ?', [$adapterHash])
        );

        // 游标：适配器返回的 PDOStatement 与静态调用同形同内容
        $adapterStmt = $adapter->query($select, $params);
        $directStmt = $facade::query($select, $params);
        $this->assertInstanceOf(PDOStatement::class, $adapterStmt);
        $this->assertSame($directStmt->fetchAll(), $adapterStmt->fetchAll());

        // 更新：两侧都返回受影响行数 1，且写入值可通过另一侧读回
        $this->assertSame(1, $adapter->update(
            'uploads', ['filename' => 'adapter-updated.txt'], 'file_hash = ?', [$adapterHash]
        ));
        $this->assertSame(1, $facade::update(
            'uploads', ['filename' => 'direct-updated.txt'], 'file_hash = ?', [$directHash]
        ));
        $this->assertSame('direct-updated.txt', $adapter->fetchOne(
            'SELECT filename FROM uploads WHERE file_hash = ?', [$directHash]
        )['filename']);
        $this->assertSame('adapter-updated.txt', $facade::fetchOne(
            'SELECT filename FROM uploads WHERE file_hash = ?', [$adapterHash]
        )['filename']);

        // 删除：返回行数一致，删除后不可见
        $this->assertSame(1, $adapter->delete('uploads', 'file_hash = ?', [$adapterHash]));
        $this->assertSame(1, $facade::delete('uploads', 'file_hash = ?', [$directHash]));
        $this->assertNull($adapter->fetchOne('SELECT * FROM uploads WHERE file_hash = ?', [$adapterHash]));
        $this->assertNull($facade::fetchOne('SELECT * FROM uploads WHERE file_hash = ?', [$directHash]));
    }

    public function testDatabaseAdapterTransactionSemanticsMatchStaticFacade(): void
    {
        $adapter = new StaticDatabaseAdapter();
        $facade = self::DB_FACADE;
        $hash = 'tx_' . substr(md5(uniqid('tx-adapter', true)), 0, 10);
        $sql = 'SELECT * FROM uploads WHERE file_hash = ?';

        $adapter->beginTransaction();
        $adapter->insert('uploads', ['file_hash' => $hash, 'status' => 1]);
        $this->assertNotNull($adapter->fetchOne($sql, [$hash]));
        $adapter->rollBack();
        $this->assertNull($adapter->fetchOne($sql, [$hash]));

        $adapter->beginTransaction();
        $adapter->insert('uploads', ['file_hash' => $hash, 'status' => 1]);
        $adapter->commit();
        $this->assertSame(
            '1',
            (string)$facade::fetchColumn('SELECT COUNT(*) FROM uploads WHERE file_hash = ?', [$hash])
        );

        // 静态门面的防御性语义：无活动事务时回滚必须静默返回
        $adapter->rollBack();
        $this->assertNotNull($adapter->fetchOne($sql, [$hash]));
    }

    public function testCacheAdapterMatchesDirectStaticCalls(): void
    {
        $adapter = new StaticCacheAdapter();
        $facade = self::CACHE_FACADE;
        $suffix = substr(md5(uniqid('cache-adapter', true)), 0, 10);
        $keyA = 'adapter_' . $suffix;
        $keyB = 'direct_' . $suffix;

        $this->assertTrue($adapter->set($keyA, ['v' => 1], 60));
        $this->assertTrue($facade::set($keyB, ['v' => 1], 60));
        $this->assertSame($facade::get($keyB), $adapter->get($keyA));
        $this->assertTrue($adapter->has($keyA));

        $this->assertTrue($adapter->delete($keyA));
        $this->assertTrue($facade::delete($keyB));
        $this->assertNull($adapter->get($keyA));
        $this->assertNull($facade::get($keyB));
        $this->assertFalse($adapter->has($keyA));

        // 前缀失效：适配器删除自己写的前缀，不影响静态门面写入的另一前缀
        $this->assertTrue($adapter->set('pa_' . $suffix, 'a', 60));
        $this->assertTrue($facade::set('pb_' . $suffix, 'b', 60));
        $adapter->deleteByPrefix('pa_' . $suffix);
        $this->assertNull($adapter->get('pa_' . $suffix));
        $this->assertSame('b', $adapter->get('pb_' . $suffix));
        $facade::deleteByPrefix('pb_' . $suffix);
        $this->assertNull($adapter->get('pb_' . $suffix));

        // 回源 + 缓存命中：第二次调用不再执行回调
        $calls = 0;
        $rememberKey = 'remember_' . $suffix;
        $first = $adapter->remember($rememberKey, function () use (&$calls) {
            $calls++;
            return 'computed';
        }, 60);
        $second = $adapter->remember($rememberKey, function () use (&$calls) {
            $calls++;
            return 'recomputed';
        }, 60);
        $this->assertSame('computed', $first);
        $this->assertSame('computed', $second);
        $this->assertSame(1, $calls);

        // 命名空间版本：适配器与静态门面都遵循「初始 1、升版后单调增长」
        $nsA = 'adapter_ns_' . $suffix;
        $nsB = 'direct_ns_' . $suffix;
        $beforeA = $adapter->nsVersion($nsA);
        $beforeB = $facade::nsVersion($nsB);
        $this->assertSame(1, $beforeA);
        $this->assertSame(1, $beforeB);
        $adapter->bumpNsVersion($nsA);
        $facade::bumpNsVersion($nsB);
        $afterA = $adapter->nsVersion($nsA);
        $this->assertGreaterThan($beforeA, $afterA);
        $this->assertGreaterThan($beforeB, $facade::nsVersion($nsB));
        $adapter->bumpNsVersionThrottled($nsA, 20);
        $this->assertGreaterThanOrEqual($afterA, $adapter->nsVersion($nsA));
    }

    public function testConsumerCanSwapDefaultAdapterForFakeImplementation(): void
    {
        $facade = self::DB_FACADE;

        // 场景一：默认适配器，消费者只依赖接口
        $defaultConsumer = new DemoConsumerForTest(new StaticDatabaseAdapter());
        $hash = 'swap_' . substr(md5(uniqid('swap-adapter', true)), 0, 10);
        $defaultConsumer->insertRow('uploads', ['file_hash' => $hash, 'status' => 1]);
        $this->assertSame(
            count($facade::fetchAll('SELECT * FROM uploads WHERE file_hash = ?', [$hash])),
            $defaultConsumer->countRows('SELECT * FROM uploads WHERE file_hash = ?', [$hash])
        );

        // 场景二：替换为测试内假实现，消费者代码一行不改
        $fake = new FakeDatabaseForTest([
            ['id' => 1, 'file_hash' => 'fake-a'],
            ['id' => 2, 'file_hash' => 'fake-b'],
        ]);
        $fakeConsumer = new DemoConsumerForTest($fake);
        $this->assertSame(2, $fakeConsumer->countRows('SELECT * FROM fake_rows'));

        $realCountBefore = (int)$facade::fetchColumn('SELECT COUNT(*) FROM uploads');
        $fakeConsumer->insertRow('fake_rows', ['file_hash' => 'fake-c']);
        $this->assertSame(3, $fakeConsumer->countRows('SELECT * FROM fake_rows'));
        $realCountAfter = (int)$facade::fetchColumn('SELECT COUNT(*) FROM uploads');
        $this->assertSame($realCountBefore, $realCountAfter, '假实现不得触碰真实静态门面');
    }
}

/**
 * 测试内假实现：内存数组 + 临时 SQLite 表，完整实现 DatabaseInterface 的 10 个方法。
 * 不引用任何静态门面，用于证明接口可被替换。
 */
final class FakeDatabaseForTest implements DatabaseInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];
    private int $nextId = 1;
    private int $depth = 0;
    private bool $dirty = true;
    private ?PDO $pdo = null;
    /** @var array<int,array<string,mixed>> */
    private array $snapshot = [];

    /** @param list<array<string,mixed>> $rows */
    public function __construct(array $rows = [])
    {
        foreach ($rows as $row) {
            $this->rows[$this->nextId++] = $row;
        }
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id] + $data;
        $this->dirty = true;
        return $id;
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $changed = 0;
        foreach ($this->rows as $id => $row) {
            if (!$this->matches($row, $where, $whereParams)) {
                continue;
            }
            foreach ($data as $key => $value) {
                $this->rows[$id][$key] = $value;
            }
            $changed++;
        }
        if ($changed > 0) {
            $this->dirty = true;
        }
        return $changed;
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $removed = 0;
        foreach ($this->rows as $id => $row) {
            if ($this->matches($row, $where, $params)) {
                unset($this->rows[$id]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->dirty = true;
        }
        return $removed;
    }

    public function beginTransaction(): void
    {
        if ($this->depth === 0) {
            $this->snapshot = $this->rows;
        }
        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
        if ($this->depth === 0) {
            $this->snapshot = [];
        }
    }

    public function rollBack(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth--;
        if ($this->depth === 0) {
            $this->rows = $this->snapshot;
            $this->snapshot = [];
            $this->dirty = true;
        }
    }

    /** 按 `字段 = ?` 条件（AND 连接）匹配内存行，够覆盖本测试的替换演示。 */
    private function matches(array $row, string $where, array $params): bool
    {
        $conditions = preg_split('/\s+AND\s+/i', trim($where)) ?: [];
        foreach ($conditions as $index => $condition) {
            if (!preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s*=\s*\?$/', trim($condition), $matches)) {
                return false;
            }
            if ((string)($row[$matches[1]] ?? '') !== (string)($params[$index] ?? '')) {
                return false;
            }
        }
        return true;
    }

    /** 惰性构建与内存行同步的 SQLite 表（仅测试用，不连接外部库）。 */
    private function connection(): PDO
    {
        if ($this->pdo !== null && !$this->dirty) {
            return $this->pdo;
        }
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $columns = ['id' => true];
        foreach ($this->rows as $row) {
            foreach (array_keys($row) as $column) {
                $name = (string)$column;
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                    $columns[$name] = true;
                }
            }
        }
        $names = array_keys($columns);
        $definitions = [];
        foreach ($names as $name) {
            $definitions[] = '`' . $name . '` TEXT';
        }
        $pdo->exec('CREATE TABLE fake_rows (' . implode(', ', $definitions) . ')');
        $insert = 'INSERT INTO fake_rows (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')';
        $stmt = $pdo->prepare($insert);
        foreach ($this->rows as $row) {
            $values = [];
            foreach ($names as $name) {
                $values[] = $row[$name] ?? null;
            }
            $stmt->execute($values);
        }
        $this->pdo = $pdo;
        $this->dirty = false;
        return $pdo;
    }
}

/** 测试内消费者：只认接口，不认具体门面，换实现无需改一行。 */
final class DemoConsumerForTest
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    public function countRows(string $sql, array $params = []): int
    {
        return count($this->db->fetchAll($sql, $params));
    }

    public function insertRow(string $table, array $data): int
    {
        return $this->db->insert($table, $data);
    }
}
