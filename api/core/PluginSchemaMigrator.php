<?php
/**
 * 插件声明式迁移器（E1-3，Q2 安全边界）
 *
 * 插件只能通过 plugin.json 的 required_tables / required_columns 声明结构，
 * 由核心生成 DDL —— 插件提交裸 SQL 一律不接受（additionalProperties:false + 关键字扫描双保险）。
 *
 * 安全边界：
 *   · 类型白名单 int/bigint/tinyint/varchar/text/longtext/datetime/decimal；varchar 必须有 length；
 *   · 表名必须 ^plugin_[A-Za-z]...，不得与 MigrationContract::REQUIRED_TABLES 同名；
 *   · required_columns 给核心表追加列时，不得与 REQUIRED_COLUMN_NAMES 同名（C8）；
 *   · 跨插件同名表由 plugin_configs._schema_tables 归属登记拦截（后安装者拒绝）；
 *   · 幂等：information_schema（SQLite 用 sqlite_master/PRAGMA）存在则跳过，缺列逐列 ALTER。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class PluginSchemaMigrator
{
    private const TYPES = ['int', 'bigint', 'tinyint', 'varchar', 'text', 'longtext', 'datetime', 'decimal'];
    private const TABLE_PATTERN = '/^plugin_[a-zA-Z][a-zA-Z0-9_]{0,55}$/';
    private const NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/';
    private const FORBIDDEN_KEYWORD = '/\b(?:DROP|TRUNCATE|RENAME)\b/i';
    /** 内部保留键，记录每个插件声明创建的表，防止插件间同名表互相覆盖 */
    private const OWNERSHIP_KEY = '_schema_tables';

    /**
     * 纯逻辑校验（不连库），返回可读错误数组。
     *
     * @return list<string>
     */
    public static function validateAgainstContract(PluginManifest $manifest): array
    {
        $errors = [];
        $coreTables = array_map('strtolower', MigrationContract::REQUIRED_TABLES);
        $coreColumns = array_map('strtolower', MigrationContract::REQUIRED_COLUMN_NAMES);

        foreach ($manifest->requiredTables() as $i => $spec) {
            $path = "required_tables[{$i}]";
            if (!is_array($spec)) {
                $errors[] = "{$path} 必须是对象";
                continue;
            }
            $table = strtolower((string)($spec['name'] ?? ''));
            if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
                $errors[] = "{$path}.name 必须以 plugin_ 开头且符合命名规范: {$table}";
            }
            if (in_array($table, $coreTables, true)) {
                $errors[] = "{$path}.name 与核心契约表同名，拒绝覆盖: {$table}";
            }
            foreach ((array)($spec['columns'] ?? []) as $j => $column) {
                $errors = array_merge($errors, self::checkColumnSpec($column, "{$path}.columns[{$j}]"));
            }
            foreach ((array)($spec['indexes'] ?? []) as $j => $index) {
                if (!is_array($index)) {
                    $errors[] = "{$path}.indexes[{$j}] 必须是对象";
                    continue;
                }
                foreach ((array)($index['columns'] ?? []) as $column) {
                    if (!is_string($column) || preg_match(self::NAME_PATTERN, $column) !== 1) {
                        $errors[] = "{$path}.indexes[{$j}].columns 含非法列名";
                    }
                }
            }
        }

        foreach ($manifest->requiredColumns() as $i => $add) {
            $path = "required_columns[{$i}]";
            if (!is_array($add)) {
                $errors[] = "{$path} 必须是对象";
                continue;
            }
            $table = strtolower((string)($add['table'] ?? ''));
            if (preg_match(self::NAME_PATTERN, $table) !== 1) {
                $errors[] = "{$path}.table 非法: {$table}";
            }
            $column = is_array($add['column'] ?? null) ? $add['column'] : [];
            $columnName = strtolower((string)($column['name'] ?? ''));
            if (in_array($table, $coreTables, true) && in_array($columnName, $coreColumns, true)) {
                $errors[] = "{$path} 与核心契约列同名，拒绝重定义: {$table}.{$columnName}";
            }
            $errors = array_merge($errors, self::checkColumnSpec($column, "{$path}.column"));
        }

        // 关键字扫描双保险：即便未来 schema 放宽，也不让 DROP/TRUNCATE/RENAME 混进声明。
        $raw = json_encode([
            'required_tables' => $manifest->requiredTables(),
            'required_columns' => $manifest->requiredColumns(),
        ], JSON_UNESCAPED_UNICODE);
        if (is_string($raw) && preg_match(self::FORBIDDEN_KEYWORD, $raw) === 1) {
            $errors[] = '声明中含禁止的 DDL 关键字（DROP/TRUNCATE/RENAME）';
        }
        return $errors;
    }

    /**
     * 执行建表 / 加列，重复执行不报错。
     *
     * @return list<string> 本次真实执行的 DDL 摘要（全幂等跳过时为空数组）
     */
    public static function apply(PluginManifest $manifest): array
    {
        $errors = array_merge($manifest->validate(), self::validateAgainstContract($manifest));
        if ($errors !== []) {
            throw new \RuntimeException('插件 schema 校验失败: ' . implode('；', $errors));
        }

        $applied = [];
        foreach ($manifest->requiredTables() as $spec) {
            $table = (string)$spec['name'];
            $owner = self::ownerOf($table);
            if ($owner !== null && $owner !== strtolower($manifest->name())) {
                throw new \RuntimeException("表 {$table} 已由插件 {$owner} 声明，后安装者拒绝");
            }
            if (!self::tableExists($table)) {
                Database::getInstance()->exec(self::buildCreateSql($spec));
                $applied[] = 'table:' . $table;
                continue;
            }
            // 表存在但列缺失：逐列补齐（与核心 migrate101 的半残库自愈同口径）
            foreach ($spec['columns'] as $column) {
                if (!self::columnExists($table, (string)$column['name'])) {
                    Database::getInstance()->exec(
                        'ALTER TABLE `' . $table . '` ADD COLUMN `'
                        . (string)$column['name'] . '` ' . self::buildAddColumnSql($column)
                    );
                    $applied[] = 'column:' . $table . '.' . $column['name'];
                }
            }
        }

        foreach ($manifest->requiredColumns() as $add) {
            $table = (string)$add['table'];
            $column = $add['column'];
            $columnName = (string)$column['name'];
            if (!self::tableExists($table)) {
                throw new \RuntimeException("目标表不存在，无法追加列: {$table}.{$columnName}");
            }
            if (!self::columnExists($table, $columnName)) {
                // SQLite 不支持 AFTER 子句，行为测试所在的内核库直接忽略（生产 MySQL 保留）
                $after = isset($add['after']) && !self::isSqlite()
                    ? ' AFTER `' . (string)$add['after'] . '`'
                    : '';
                Database::getInstance()->exec(
                    'ALTER TABLE `' . $table . '` ADD COLUMN `'
                    . $columnName . '` ' . self::buildAddColumnSql($column) . $after
                );
                $applied[] = 'column:' . $table . '.' . $columnName;
            }
        }

        self::recordOwnership($manifest);
        return $applied;
    }

    /**
     * 卸载删表：默认不删（数据保留）；只有显式授权且清单声明 drop_tables_on_uninstall 才执行。
     * required_columns 追加的列永不删除。
     *
     * @return list<string> 已删除的表名
     */
    public static function dropTables(PluginManifest $manifest, bool $authorized = false): array
    {
        if (!$authorized) {
            throw new \RuntimeException('卸载删表缺少已授权确认（confirm_token 必须由控制器校验后传入）');
        }
        if (!$manifest->dropTablesOnUninstall()) {
            return [];
        }
        $dropped = [];
        foreach ($manifest->requiredTables() as $spec) {
            $table = (string)($spec['name'] ?? '');
            if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
                throw new \RuntimeException("拒绝删除非 plugin_* 表: {$table}");
            }
            $owner = self::ownerOf($table);
            if ($owner !== null && $owner !== strtolower($manifest->name())) {
                throw new \RuntimeException("表 {$table} 不属于插件 {$manifest->name()}，拒绝删除");
            }
            Database::getInstance()->exec('DROP TABLE IF EXISTS `' . $table . '`');
            $dropped[] = $table;
        }
        self::clearOwnership($manifest);
        return $dropped;
    }

    /** @return list<string> */
    private static function checkColumnSpec(mixed $spec, string $path): array
    {
        $errors = [];
        if (!is_array($spec)) {
            return ["{$path} 必须是对象"];
        }
        $name = (string)($spec['name'] ?? '');
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = "{$path}.name 非法: {$name}";
        }
        $type = (string)($spec['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = "{$path}.type 不在白名单: {$type}";
        }
        if ($type === 'varchar' && (!isset($spec['length']) || !is_int($spec['length']))) {
            $errors[] = "{$path}.length varchar 必须声明整数 length";
        }
        return $errors;
    }

    private static function buildCreateSql(array $spec): string
    {
        $table = (string)$spec['name'];
        $parts = [];
        foreach ($spec['columns'] as $column) {
            $parts[] = '`' . $column['name'] . '` ' . self::buildAddColumnSql($column);
        }
        if (!empty($spec['primary_key'])) {
            $parts[] = 'PRIMARY KEY (`' . $spec['primary_key'] . '`)';
        }
        if (!self::isSqlite()) {
            // SQLite 不接受内联 KEY/COMMENT 语法，行为测试只验证表列落地。
            foreach ((array)($spec['indexes'] ?? []) as $index) {
                $columns = '`' . implode('`, `', (array)$index['columns']) . '`';
                $parts[] = (!empty($index['unique']) ? 'UNIQUE KEY' : 'KEY')
                    . ' `' . $index['name'] . '` (' . $columns . ')';
            }
        }
        return 'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $parts) . ')';
    }

    private static function buildAddColumnSql(array $spec): string
    {
        $type = (string)$spec['type'];
        if ($type === 'varchar') {
            $sql = 'varchar(' . (int)($spec['length'] ?? 64) . ')';
        } elseif ($type === 'decimal') {
            $sql = 'decimal(' . (int)($spec['length'] ?? 10) . ',2)';
        } else {
            $sql = $type;
        }
        if (!self::isSqlite()) {
            if (!empty($spec['unsigned']) && in_array($type, ['int', 'bigint', 'tinyint', 'decimal'], true)) {
                $sql .= ' UNSIGNED';
            }
            $sql .= !empty($spec['nullable']) ? ' NULL' : ' NOT NULL';
            if (array_key_exists('default', $spec) && $spec['default'] !== null) {
                $default = is_int($spec['default'])
                    ? (string)$spec['default']
                    : self::quoteSqlString((string)$spec['default']);
                $sql .= ' DEFAULT ' . $default;
            } elseif (!empty($spec['nullable'])) {
                $sql .= ' DEFAULT NULL';
            }
            if (!empty($spec['comment'])) {
                $sql .= ' COMMENT ' . self::quoteSqlString((string)$spec['comment']);
            }
            if (!empty($spec['auto_increment'])) {
                $sql .= ' AUTO_INCREMENT';
            }
        }
        return $sql;
    }

    /**
     * 转义 DDL 字符串字面量：MySQL 默认 sql_mode 下反斜杠本身是转义符，必须一并双写，
     * 否则以 \ 结尾的 comment/default 会让字符串不闭合；SQLite 没有反斜杠转义语义。
     */
    private static function quoteSqlString(string $value): string
    {
        $escaped = str_replace("'", "''", $value);
        if (!self::isSqlite()) {
            $escaped = str_replace('\\', '\\\\', $escaped);
        }
        return "'" . $escaped . "'";
    }

    private static function isSqlite(): bool
    {
        try {
            return Database::getInstance()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function tableExists(string $table): bool
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1 && preg_match(self::NAME_PATTERN, $table) !== 1) {
            return false;
        }
        if (self::isSqlite()) {
            return (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) > 0;
        }
        return (int)Database::fetchColumn(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    private static function columnExists(string $table, string $column): bool
    {
        if (preg_match(self::NAME_PATTERN, $column) !== 1) {
            return false;
        }
        if (self::isSqlite()) {
            foreach (Database::fetchAll('PRAGMA table_info(`' . $table . '`)') as $row) {
                if (strtolower((string)($row['name'] ?? '')) === strtolower($column)) {
                    return true;
                }
            }
            return false;
        }
        return (int)Database::fetchColumn(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    /** 读取某个表被哪个插件声明过 */
    private static function ownerOf(string $table): ?string
    {
        try {
            $rows = Database::fetchAll(
                'SELECT `plugin_name`, `config_value` FROM `plugin_configs` WHERE `config_key` = ?',
                [self::OWNERSHIP_KEY]
            );
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($rows as $row) {
            $list = json_decode((string)($row['config_value'] ?? ''), true);
            if (is_array($list) && in_array($table, $list, true)) {
                return strtolower((string)$row['plugin_name']);
            }
        }
        return null;
    }

    private static function recordOwnership(PluginManifest $manifest): void
    {
        $tables = [];
        foreach ($manifest->requiredTables() as $spec) {
            $tables[] = (string)($spec['name'] ?? '');
        }
        if ($tables === []) {
            return;
        }
        try {
            $name = $manifest->name();
            $row = Database::fetchOne(
                'SELECT `config_value` FROM `plugin_configs` WHERE `plugin_name` = ? AND `config_key` = ?',
                [$name, self::OWNERSHIP_KEY]
            );
            $owned = $row === null ? [] : (json_decode((string)$row['config_value'], true) ?: []);
            $merged = array_values(array_unique(array_merge(is_array($owned) ? $owned : [], $tables)));
            $value = (string)json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($row === null) {
                Database::insert('plugin_configs', [
                    'plugin_name' => $name,
                    'config_key' => self::OWNERSHIP_KEY,
                    'config_value' => $value,
                ]);
            } else {
                Database::update(
                    'plugin_configs',
                    ['config_value' => $value],
                    '`plugin_name` = ? AND `config_key` = ?',
                    [$name, self::OWNERSHIP_KEY]
                );
            }
        } catch (\Throwable $e) {
            // 归属登记失败不回滚已建的表；表前缀与契约校验已经把风险挡在外面。
            Logger::error('[Hublog PluginSchema] 归属登记失败: ' . $e->getMessage());
        }
    }

    private static function clearOwnership(PluginManifest $manifest): void
    {
        try {
            Database::delete(
                'plugin_configs',
                '`plugin_name` = ? AND `config_key` = ?',
                [$manifest->name(), self::OWNERSHIP_KEY]
            );
        } catch (\Throwable $e) {
            Logger::error('[Hublog PluginSchema] 清理归属登记失败: ' . $e->getMessage());
        }
    }
}
