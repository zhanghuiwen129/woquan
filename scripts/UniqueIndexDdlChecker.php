<?php
/**
 * 幂等唯一键 DDL 静态比对器（构建期门禁与单测共用同一份口径）
 *
 * 只读迁移源码的 SQL 字符串字面量，还原每个 UNIQUE KEY 的实际列清单，
 * 再与 MigrationContract::REQUIRED_UNIQUE_INDEXES 的 cols 做「集合一致」比对。
 * 不连数据库、不加载迁移类、不执行任何 DDL。
 *
 * 之所以存在：运行时 check-idempotency.php 需要 config.php + MySQL，CI 恒为 WARN；
 * 把「索引名 / 列清单是否在迁移源码里真实登记」前移到构建期，才能让回归真正阻断。
 */

declare(strict_types=1);

final class UniqueIndexDdlChecker
{
    /** 不与索引列混淆的 DDL 关键字（出现在括号里时不是列名） */
    private const NON_COLUMN_TOKENS = ['comment', 'using', 'btree', 'hash', 'asc', 'desc'];

    /**
     * 只保留源码中的 SQL 字符串字面量（含 heredoc），注释与 PHP 逻辑全部丢弃。
     * 与 MigrationContractScanner::sqlLiterals() 同口径，避免注释里的示例 DDL 误报。
     */
    public static function sqlLiterals(string $src): string
    {
        $out = [];
        foreach (token_get_all($src) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            ) {
                $out[] = $token[1];
            }
        }
        return implode("\n", $out);
    }

    /**
     * 收集 DDL 中的 UNIQUE KEY / UNIQUE INDEX 列清单。
     *
     * 覆盖两种写法：CREATE TABLE 内联 `UNIQUE KEY \`uk_x\` (\`a\`, \`b\`)`
     * 与 ALTER TABLE ... `ADD UNIQUE KEY \`uk_x\` (\`a\`)`。
     * 同名索引可能出现在多张表（如 uk_order_no），保留所有列写法变体。
     *
     * @return array<string,list<list<string>>> 小写索引名 => 列清单变体（均小写）
     */
    public static function collectIndexColumns(string $sql): array
    {
        $indexes = [];
        if (!preg_match_all('/\bUNIQUE\s+(?:KEY|INDEX)\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)/i', $sql, $matches, PREG_SET_ORDER)) {
            return $indexes;
        }
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            preg_match_all(
                '/(?:^|,)\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?(?:\s*\(\s*\d+\s*\))?/i',
                $match[2],
                $columnMatches
            );
            $cols = [];
            foreach ($columnMatches[1] as $raw) {
                $col = strtolower($raw);
                if (!in_array($col, self::NON_COLUMN_TOKENS, true)) {
                    $cols[] = $col;
                }
            }
            if (!in_array($cols, $indexes[$name] ?? [], true)) {
                $indexes[$name][] = $cols;
            }
        }
        return $indexes;
    }

    /**
     * 直接对一段 SQL 文本执行契约比对（便于注入假 DDL 做变异测试）。
     *
     * @param list<array{table:string,index:string,cols:list<string>,why?:string}> $specs
     * @return list<array{table:string,index:string,expected:list<string>,actual:list<string>,reason:string}>
     */
    public static function checkSql(string $sql, array $specs): array
    {
        return self::compare($specs, self::collectIndexColumns($sql));
    }

    /**
     * 比对契约与 DDL：索引名存在且列集合一致才通过（列顺序不强求，大小写不敏感）。
     *
     * @param list<array{table:string,index:string,cols:list<string>,why?:string}> $specs
     * @param array<string,list<list<string>>> $ddlIndexes
     * @return list<array{table:string,index:string,expected:list<string>,actual:list<string>,reason:string}>
     */
    public static function compare(array $specs, array $ddlIndexes): array
    {
        $issues = [];
        foreach ($specs as $spec) {
            $index = strtolower((string)$spec['index']);
            $expected = array_map(static fn($c): string => strtolower((string)$c), $spec['cols']);
            sort($expected, SORT_STRING);

            $variants = $ddlIndexes[$index] ?? [];
            $matched = false;
            foreach ($variants as $variant) {
                $actual = $variant;
                sort($actual, SORT_STRING);
                if ($actual === $expected) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            $actual = $variants[0] ?? [];
            sort($actual, SORT_STRING);
            $issues[] = [
                'table' => (string)$spec['table'],
                'index' => (string)$spec['index'],
                'expected' => $expected,
                'actual' => $actual,
                'reason' => $variants === [] ? 'index_missing' : 'columns_mismatch',
            ];
        }
        return $issues;
    }
}
