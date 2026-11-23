<?php
/**
 * 从迁移源码重新生成 api/core/MigrationContract.php
 *
 *   php scripts/build_migration_contract.php --report   # 只看诊断，不写文件
 *   php scripts/build_migration_contract.php --write    # 重新生成契约
 *   php scripts/build_migration_contract.php --check    # 校验现有契约是否最新（CI 用，退出码 1 = 过期）
 *
 * 新增 migrateNN / 新表 / 新列后必须执行 --write 并提交生成的契约；
 * 否则 MigrationContractTest 与 CI 会直接报「契约过期」。
 *
 * 契约分两段：
 *   - REQUIRED_TABLES / REQUIRED_COLUMN_NAMES：本脚本从源码自动生成，勿手工改；
 *   - CRITICAL_COLUMNS / REQUIRED_UNIQUE_INDEXES：人工维护的高价值哨兵，生成器原样保留。
 *
 * 交叉校验：
 *   REQUIRED_UNIQUE_INDEXES 里登记的每个索引名，都必须真实存在于迁移源码中；
 *   否则直接判定「幽灵键」并退出码 1 —— 防止登记出一个数据库里永远不会存在的键。
 */

declare(strict_types=1);

require_once __DIR__ . '/MigrationContractScanner.php';

$root = dirname(__DIR__);
$target = $root . '/api/core/MigrationContract.php';
$mode = in_array('--write', $argv, true) ? 'write'
    : (in_array('--check', $argv, true) ? 'check' : 'report');

$scan = MigrationContractScanner::scan($root);

// 人工维护段（CRITICAL_COLUMNS / REQUIRED_UNIQUE_INDEXES）从现有契约里原样搬运，
// 不在生成器里再抄一份 —— 抄两份必然漂移，而这两段恰恰是最需要人来把关的。
$currentSource = is_file($target) ? (string)file_get_contents($target) : '';
$criticalColumns = extractConstBlock($currentSource, 'CRITICAL_COLUMNS')
    ?? throw new RuntimeException('现有契约里找不到 CRITICAL_COLUMNS，请人工补齐后再生成');
$uniqueIndexes = extractConstBlock($currentSource, 'REQUIRED_UNIQUE_INDEXES')
    ?? throw new RuntimeException('现有契约里找不到 REQUIRED_UNIQUE_INDEXES，请人工补齐后再生成');

// 交叉校验：登记的幂等唯一键必须真实存在于迁移源码，否则是「幽灵键」
$registeredIndexNames = [];
foreach (extractUniqueIndexNames($uniqueIndexes) as $name) {
    $registeredIndexNames[] = $name;
}
$ghost = array_values(array_diff($registeredIndexNames, $scan['uniqueIndexNames']));
if ($ghost !== []) {
    fwrite(
        STDERR,
        "[contract] 幽灵幂等键：契约登记了迁移源码里不存在的唯一索引 -> "
        . implode(', ', $ghost)
        . "\n        请修正 api/core/MigrationContract.php 的 REQUIRED_UNIQUE_INDEXES，或补上对应迁移。\n"
    );
    exit(1);
}

if ($mode === 'report') {
    echo '扫描源码: ' . implode(', ', $scan['sourceFiles']) . "\n";
    echo '表: ' . count($scan['tables']) . ' 张'
        . '（DROP 过: ' . ($scan['droppedTables'] ? implode(',', $scan['droppedTables']) : '无') . "）\n";
    echo 'ADD COLUMN 列名去重: ' . count($scan['columnNames']) . ' 个'
        . '（DROP 过: ' . ($scan['droppedColumnNames'] ? implode(',', $scan['droppedColumnNames']) : '无') . "）\n";
    echo '源码唯一索引: ' . count($scan['uniqueIndexNames']) . " 个\n";
    echo '契约登记的幂等唯一键: ' . count($registeredIndexNames) . ' 条（覆盖 '
        . count(array_unique($registeredIndexNames)) . " 个索引名，全部命中源码）\n";    echo "\n--- 表清单 ---\n" . implode(', ', $scan['tables']) . "\n";
    echo "\n--- 列名清单 ---\n" . implode(', ', $scan['columnNames']) . "\n";
    echo "\n--- 源码唯一索引名 ---\n" . implode(', ', $scan['uniqueIndexNames']) . "\n";
    echo "\n运行 --write 生成契约。\n";
    exit(0);
}

$generated = renderContract($scan, $criticalColumns, $uniqueIndexes);

if ($mode === 'check') {
    $ok = $currentSource === $generated;
    echo $ok
        ? "契约最新（" . count($scan['tables']) . " 表 / " . count($scan['columnNames']) . " 列）\n"
        : "契约过期或缺失，请执行: php scripts/build_migration_contract.php --write\n";
    exit($ok ? 0 : 1);
}

file_put_contents($target, $generated, LOCK_EX);
echo "已生成 {$target}\n";
echo '表 ' . count($scan['tables']) . ' 张 / 列名 ' . count($scan['columnNames'])
    . ' 个 / 幂等唯一键 ' . count($registeredIndexNames) . " 个\n";
exit(0);

/**
 * 从 PHP 源码里取出 `public const XXX = [ ... ];` 的整段文本（含缩进），供原样搬运。
 */
function extractConstBlock(string $source, string $constName): ?string
{
    $pattern = '/(^[ \t]*public const ' . preg_quote($constName, '/') . ' = \[.*?^\s*\];)/ms';
    if (!preg_match($pattern, $source, $m)) {
        return null;
    }
    return rtrim($m[1]);
}

/** 从搬运出来的常量块里解析出登记的索引名。 */
function extractUniqueIndexNames(string $block): array
{
    $names = [];
    if (preg_match_all("/'index'\s*=>\s*'([a-z0-9_]+)'/i", $block, $m)) {
        $names = $m[1];
    }
    return $names;
}

/**
 * @param array<string,mixed> $scan
 */
function renderContract(array $scan, string $criticalColumns, string $uniqueIndexes): string
{
    $tables = '';
    foreach ($scan['tables'] as $t) {
        $tables .= "        '" . $t . "',\n";
    }
    $columnNames = '';
    foreach ($scan['columnNames'] as $c) {
        $columnNames .= "        '" . $c . "',\n";
    }
    $tableCount = count($scan['tables']);
    $columnCount = count($scan['columnNames']);
    $dropped = $scan['droppedTables'] ? implode(', ', $scan['droppedTables']) : '无';

    return <<<PHP
<?php
/**
 * 迁移终态契约 —— 由 scripts/build_migration_contract.php 生成，勿手工编辑自动段。
 *
 * 单一数据源：迁移源码里所有 CREATE TABLE / ADD COLUMN 的期望终态。
 * 新增迁移后必须重新执行生成器，否则 MigrationContractTest 与 CI 会失败。
 *
 * 用途：
 *   1. Migration::schemaDiff() 用它做「装完 / 升完」的终态自检，漏登的表列不再可能静默通过；
 *   2. 每个 migrateNN 内部 223 个吞异常 catch 因此有了统一兜底 ——
 *      只要终态契约没满足，就抛异常并回退版本号，绝不把半残库标记成成功。
 *
 * 为什么按「表名 + 列名」而不是「表.列」精确对：
 *   同一 migrateNN 里常先改 A 表再批量改 B 表，静态推断列归属会把
 *   circles.announcement 挂到 posts、posts.link_url 挂到 comments（实测误判）。
 *   按列名校验零误报，且足以拦住「DDL 被 catch 吞掉导致整列没建出来」；
 *   高价值列另有 CRITICAL_COLUMNS 做精确「表.列」哨兵。
 *
 * 幂等唯一键是独立的第四个维度：表 / 列都在，但唯一键没建出来的情况
 * （migrate92 的 DDL 被 catch 吞掉）用前三个维度查不出来，而它直接决定
 * 重复支付回调会不会重复入账。见 REQUIRED_UNIQUE_INDEXES。
 *
 * 生成依据：{$tableCount} 张表 / {$columnCount} 个列名
 * 已排除被 DROP 的表：{$dropped}
 */

declare(strict_types=1);

namespace Hublog\\Core;

final class MigrationContract
{
    /** 故意跳号、没有对应 migrateNN 方法的版本（run() 允许推进但不执行 DDL） */
    public const INTENTIONAL_GAPS = [60, 64];

    /** @var list<string> 期望存在的全部表（自动生成） */
    public const REQUIRED_TABLES = [
{$tables}    ];

    /** @var list<string> 期望在库中出现的全部增量列名（自动生成，至少一张表拥有即可） */
    public const REQUIRED_COLUMN_NAMES = [
{$columnNames}    ];

    /**
     * 人工维护的高价值精确哨兵：这些「表.列」必须在指定表上，不能只靠同名列放行。
     * 来源于原 Migration::criticalColumnsExist() 的业务关键列，新增时请照口径补。
     *
     * @var list<array{0:string,1:string}>
     */
{$criticalColumns}

    /**
     * 资金 / 权益幂等唯一键（人工维护，与 CRITICAL_COLUMNS 同口径）。
     *
     * 历史事故：migrate92 的三段幂等键 DDL 全程被 catch 吞掉，站点版本号照样推进到最终态，
     * 唯一键根本没建出来 —— 而「表 / 列」维度的终态校验完全看不出来。
     * 后果是重复支付回调可重复入账、订单重放可重复发券。
     *
     * 这些键是资金链路的最后一道闸（应用层「先查后写」在并发 / 崩溃重试 / 回调重放下不可靠）。
     * 索引名由 scripts/build_migration_contract.php 交叉校验「必须真实存在于迁移源码」，
     * 防止登记出根本不存在的幽灵键。
     *
     * @var list<array{table:string,index:string,cols:list<string>,why:string}>
     */
{$uniqueIndexes}

    /**
     * 纯逻辑比对，不碰数据库 —— 便于行为级测试直接注入假的库结构。
     *
     * @param array<string,bool> \$existingTables        键为表名
     * @param array<string,bool> \$existingColumnNames   键为列名
     * @param array<string,bool> \$existingColumnKeys    键为 "表名.列名"
     * @param array<string,bool> \$existingUniqueIndexes 键为 "表名.索引名"（幂等唯一键维度，可省略）
     * @return array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes: list<string>}
     */
    public static function diff(
        array \$existingTables,
        array \$existingColumnNames,
        array \$existingColumnKeys,
        array \$existingUniqueIndexes = []
    ): array {
        \$missingTables = [];
        foreach (self::REQUIRED_TABLES as \$table) {
            if (!isset(\$existingTables[\$table])) {
                \$missingTables[] = \$table;
            }
        }

        \$missingColumnNames = [];
        foreach (self::REQUIRED_COLUMN_NAMES as \$name) {
            if (!isset(\$existingColumnNames[\$name])) {
                \$missingColumnNames[] = \$name;
            }
        }

        \$missingColumns = [];
        foreach (self::CRITICAL_COLUMNS as [\$table, \$column]) {
            if (in_array(\$table, \$missingTables, true)) {
                continue;
            }
            \$key = \$table . '.' . \$column;
            if (!isset(\$existingColumnKeys[\$key])) {
                \$missingColumns[] = \$key;
            }
        }

        // 幂等唯一键维度。表本身缺失时不再重复报（表缺失已经报过了）。
        \$missingUniqueIndexes = [];
        foreach (self::REQUIRED_UNIQUE_INDEXES as \$spec) {
            if (in_array(\$spec['table'], \$missingTables, true)) {
                continue;
            }
            \$key = \$spec['table'] . '.' . \$spec['index'];
            if (!isset(\$existingUniqueIndexes[\$key])) {
                \$missingUniqueIndexes[] = \$key;
            }
        }

        return [
            'tables' => \$missingTables,
            'columnNames' => \$missingColumnNames,
            'columns' => \$missingColumns,
            'uniqueIndexes' => \$missingUniqueIndexes,
        ];
    }

    /**
     * 结构维度是否完整 —— 表 / 列齐了没有。
     *
     * 只覆盖「结构」：唯一索引缺失**不**阻断升级，原因见 REQUIRED_UNIQUE_INDEXES 的说明
     * 与 Migration::ensureIdempotencyIndexes() 的处置（自动补建 + 巡检标记），
     * 刻意不抛异常回退版本号：那会让站点在「历史数据有重复、补建必然失败」的表上
     * 陷入「回退 → 重跑迁移 → 再失败 → 再回退」的无限循环。
     *
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} \$diff
     */
    public static function isComplete(array \$diff): bool
    {
        return \$diff['tables'] === []
            && \$diff['columnNames'] === []
            && \$diff['columns'] === [];
    }

    /**
     * 结构 + 幂等唯一键全部满足。仅用于「全新安装」这类没有历史脏数据的场景。
     *
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} \$diff
     */
    public static function isFullyComplete(array \$diff): bool
    {
        return self::isComplete(\$diff)
            && (\$diff['uniqueIndexes'] ?? []) === [];
    }

    /**
     * @param array{tables: list<string>, columnNames: list<string>, columns: list<string>, uniqueIndexes?: list<string>} \$diff
     */
    public static function describe(array \$diff): string
    {
        \$parts = [];
        if (\$diff['tables'] !== []) {
            \$parts[] = '缺表(' . count(\$diff['tables']) . '): ' . implode(', ', \$diff['tables']);
        }
        if (\$diff['columnNames'] !== []) {
            \$parts[] = '缺列(' . count(\$diff['columnNames']) . '): ' . implode(', ', \$diff['columnNames']);
        }
        if (\$diff['columns'] !== []) {
            \$parts[] = '关键列缺失: ' . implode(', ', \$diff['columns']);
        }
        if ((\$diff['uniqueIndexes'] ?? []) !== []) {
            \$parts[] = '缺幂等唯一键(' . count(\$diff['uniqueIndexes']) . '): ' . implode(', ', \$diff['uniqueIndexes']);
        }
        return \$parts === [] ? '契约完整' : implode(' | ', \$parts);
    }
}
PHP;
}
