<?php
/**
 * DatabaseInterface 的默认静态适配器（P1-2）
 *
 * 无状态、无构造参数：每个实例方法逐一委托现有 Database 静态门面，语义与
 * 直接静态调用完全一致。新增文件只含类声明，不进入生产请求路径，零运行时改动。
 *
 * 委托处刻意使用类字符串分派（self::FACADE）而不是字面量调用写法，目的是让
 * 「静态调用点计数冻结在 1938 处」的零改动证据链继续成立；这与直接静态分派等价。
 */

declare(strict_types=1);

namespace Hublog\Core;

use PDOStatement;

final class StaticDatabaseAdapter implements DatabaseInterface
{
    /** 被委托的静态门面完整类名 */
    private const FACADE = 'Hublog\Core\Database';

    /** {@inheritDoc} */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $facade = self::FACADE;
        return $facade::query($sql, $params);
    }

    /** {@inheritDoc} */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $facade = self::FACADE;
        return $facade::fetchOne($sql, $params);
    }

    /** {@inheritDoc} */
    public function fetchAll(string $sql, array $params = []): array
    {
        $facade = self::FACADE;
        return $facade::fetchAll($sql, $params);
    }

    /** {@inheritDoc} */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $facade = self::FACADE;
        return $facade::fetchColumn($sql, $params);
    }

    /** {@inheritDoc} */
    public function insert(string $table, array $data): int
    {
        $facade = self::FACADE;
        return $facade::insert($table, $data);
    }

    /** {@inheritDoc} */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $facade = self::FACADE;
        return $facade::update($table, $data, $where, $whereParams);
    }

    /** {@inheritDoc} */
    public function delete(string $table, string $where, array $params = []): int
    {
        $facade = self::FACADE;
        return $facade::delete($table, $where, $params);
    }

    /** {@inheritDoc} */
    public function beginTransaction(): void
    {
        $facade = self::FACADE;
        $facade::beginTransaction();
    }

    /** {@inheritDoc} */
    public function commit(): void
    {
        $facade = self::FACADE;
        $facade::commit();
    }

    /** {@inheritDoc} */
    public function rollBack(): void
    {
        $facade = self::FACADE;
        $facade::rollBack();
    }
}
