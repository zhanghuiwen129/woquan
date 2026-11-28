<?php
/**
 * 数据库可替换点契约（P1-2）
 *
 * 只收仓储层真正需要的 10 个读写原语（含事务边界），不暴露连接对象与运维统计：
 *   - getInstance() 返回单例 PDO，与未来读写分离 / 多租户按租户选连接的语义冲突；
 *   - 慢查询统计、快照开关属于现有静态门面的内部状态，替换实现无需共享。
 *
 * 本文件只有接口声明、无顶层执行代码；现有 1938 处静态调用点一行不改。
 * 默认实现见 StaticDatabaseAdapter（委托现有 Database 静态门面）。
 */

declare(strict_types=1);

namespace Hublog\Core;

use PDOStatement;

interface DatabaseInterface
{
    /** 执行参数化 SQL，返回可消费的结果集游标。 */
    public function query(string $sql, array $params = []): PDOStatement;

    /** 读取单行；无匹配返回 null。 */
    public function fetchOne(string $sql, array $params = []): ?array;

    /** 读取多行；无匹配返回空数组。 */
    public function fetchAll(string $sql, array $params = []): array;

    /** 读取首行首列；无匹配返回 null。 */
    public function fetchColumn(string $sql, array $params = []): mixed;

    /** 插入一行，返回新记录主键。 */
    public function insert(string $table, array $data): int;

    /** 按条件更新，返回受影响行数。 */
    public function update(string $table, array $data, string $where, array $whereParams = []): int;

    /** 按条件删除，返回受影响行数；实现必须拒绝无条件删除。 */
    public function delete(string $table, string $where, array $params = []): int;

    /** 开启事务。 */
    public function beginTransaction(): void;

    /** 提交事务。 */
    public function commit(): void;

    /** 回滚事务；无活动事务时应静默返回。 */
    public function rollBack(): void;
}
