<?php
/**
 * 迁移契约扫描器（CI / 生成器 / 单测共用同一份解析逻辑）
 *
 * 目的：干掉「写迁移 + 手工登记自检清单」这套双份维护。
 * 历史事故：新增表/列忘记登记进 Migration::criticalTablesExist() / criticalColumnsExist()，
 * 自检照样通过，站点带着残库运行，直到某天访问到那张表才炸 SQL；
 * 漏登 4 张表、86 处 ADD COLUMN 只登记 16 列就是这么来的。
 *
 * 做法：用 PHP tokenizer 只取出源码里的「字符串字面量」（DDL 全在单引号串 / 双引号串 /
 * heredoc 里），再从中抽取 CREATE TABLE / DROP TABLE / ADD COLUMN / DROP COLUMN。
 * 这样注释、示例代码、PHP 逻辑都不会被误当成 DDL，也不需要猜「这一列属于哪张表」——
 * 契约按「表名集合 + 列名集合」校验，规避启发式表归属推断的误报风险
 * （曾把 circles.announcement 挂到 posts、posts.link_url 挂到 comments）。
 *
 * 结果由 MigrationContract.php 固化，并由 MigrationContractTest 断言两者一致：
 * 以后谁新增迁移不重新生成契约，CI 直接红。
 *
 * 纯静态分析，不连接数据库、不加载迁移类、不执行任何 DDL。
 */

declare(strict_types=1);

final class MigrationContractScanner
{
    /** 参与扫描的源码（相对仓库根目录） */
    private const SOURCES = [
        'api/core/Migration.php',
        'api/core/MigrationHistory.php',
        'api/core/MigrationBatchA.php',
        'api/core/MigrationBatchB.php',
        'api/core/MigrationBatchC.php',
    ];

    /** @return list<string> */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    /**
     * @return array{
     *   tables: list<string>,
     *   columnNames: list<string>,
     *   droppedTables: list<string>,
     *   droppedColumnNames: list<string>,
     *   uniqueIndexNames: list<string>,
     *   sourceFiles: list<string>
     * }
     */
    public static function scan(string $root): array
    {
        $tables = [];
        $droppedTables = [];
        $columnNames = [];
        $droppedColumnNames = [];
        $uniqueIndexNames = [];
        $sourceFiles = [];

        foreach (self::SOURCES as $rel) {
            $path = rtrim($root, '/\\') . '/' . $rel;
            if (!is_file($path)) {
                throw new RuntimeException("迁移源码不存在: {$rel}");
            }
            $sourceFiles[] = $rel;
            $sql = self::sqlLiterals((string)file_get_contents($path));

            self::collect(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([a-z0-9_]+)`/i',
                $sql,
                $tables
            );
            self::collect(
                '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i',
                $sql,
                $droppedTables
            );
            // ADD COLUMN `c` / ADD COLUMN IF NOT EXISTS `c` / 简写 ADD `c` TYPE
            self::collect(
                '/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`([a-z0-9_]+)`'
                . '|ADD\s+`([a-z0-9_]+)`\s+(?:int|tinyint|smallint|bigint|varchar|char|text|mediumtext|longtext|datetime|timestamp|date|decimal|float|double|json|enum|blob)/i',
                $sql,
                $columnNames
            );
            self::collect(
                '/DROP\s+COLUMN\s+`?([a-z0-9_]+)`?/i',
                $sql,
                $droppedColumnNames
            );
            // 唯一索引名：UNIQUE KEY `uk_x` / UNIQUE INDEX `uk_x` / CREATE UNIQUE INDEX `uk_x`
            // 只取「索引名」不推断所属表 —— 表归属在 CREATE TABLE 内联写法下不可靠
            // （见文件头说明），而索引名本身在全库唯一，足以交叉校验契约是否登记了幽灵键。
            self::collect(
                '/(?:UNIQUE\s+(?:KEY|INDEX)|CREATE\s+UNIQUE\s+INDEX)\s+`?([a-z0-9_]+)`?/i',
                $sql,
                $uniqueIndexNames
            );
        }

        $tableList = array_values(array_filter(
            array_keys($tables),
            static fn(string $t): bool => !isset($droppedTables[$t])
        ));
        sort($tableList, SORT_STRING);

        $columnList = array_values(array_filter(
            array_keys($columnNames),
            static fn(string $c): bool => !isset($droppedColumnNames[$c])
        ));
        sort($columnList, SORT_STRING);

        $dropped = array_keys($droppedTables);
        sort($dropped, SORT_STRING);
        $droppedCols = array_keys($droppedColumnNames);
        sort($droppedCols, SORT_STRING);

        $uniqueIndexes = array_keys($uniqueIndexNames);
        sort($uniqueIndexes, SORT_STRING);

        return [
            'tables' => $tableList,
            'columnNames' => $columnList,
            'droppedTables' => $dropped,
            'droppedColumnNames' => $droppedCols,
            'uniqueIndexNames' => $uniqueIndexes,
            'sourceFiles' => $sourceFiles,
        ];
    }

    /**
     * 只保留 SQL 字符串字面量（含 heredoc），注释与 PHP 代码全部丢弃。
     */
    private static function sqlLiterals(string $src): string
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

    /** @param array<string,bool> $bucket */
    private static function collect(string $pattern, string $subject, array &$bucket): void
    {
        if (!preg_match_all($pattern, $subject, $m)) {
            return;
        }
        // 多分支正则：第 1 组为主，未命中时用第 2 组（ADD `c` TYPE 简写）
        foreach ($m[1] as $i => $hit) {
            $name = $hit !== '' ? $hit : ($m[2][$i] ?? '');
            if ($name !== '') {
                $bucket[strtolower($name)] = true;
            }
        }
    }
}
