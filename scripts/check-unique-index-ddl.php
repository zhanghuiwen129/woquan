<?php
/**
 * 幂等唯一键「迁移源码 DDL」构建期静态门禁
 *
 * 用法：php scripts/check-unique-index-ddl.php
 * 退出码：0 = 契约登记的 17 个索引都能在迁移 DDL 中找到且列集合一致；1 = 不一致。
 *
 * 与 scripts/check-idempotency.php 的分工：
 *   - 本脚本不连库，在构建期拦截「契约与迁移 DDL 漂移」（索引名对但列写错也算不一致）；
 *   - check-idempotency.php 连库只读核验线上是否真的建出了这些索引，是运行时辅助。
 *
 * 比对口径见 scripts/UniqueIndexDdlChecker.php：列集合一致即可，顺序与大小写不敏感。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/UniqueIndexDdlChecker.php';
require_once $root . '/scripts/MigrationContractScanner.php';
require_once $root . '/api/core/MigrationContract.php';

$sql = '';
foreach (MigrationContractScanner::sources() as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "[unique-ddl] 迁移源码不存在: {$rel}\n");
        exit(1);
    }
    $sql .= "\n" . UniqueIndexDdlChecker::sqlLiterals((string)file_get_contents($path));
}

$specs = \Hublog\Core\MigrationContract::REQUIRED_UNIQUE_INDEXES;
$issues = UniqueIndexDdlChecker::checkSql($sql, $specs);

echo '[unique-ddl] ' . date('c') . ' 静态核验开始（契约登记 ' . count($specs) . " 项）\n";
echo '[unique-ddl] 源码: ' . implode(', ', MigrationContractScanner::sources()) . "\n";

if ($issues === []) {
    echo '[unique-ddl] 通过：' . count($specs) . " 个幂等唯一键的 DDL 列清单与契约一致\n";
    exit(0);
}

foreach ($issues as $issue) {
    echo sprintf(
        "[unique-ddl] [FAIL] %s.%s (%s)：契约=[%s] DDL=[%s]\n",
        $issue['table'],
        $issue['index'],
        $issue['reason'],
        implode(', ', $issue['expected']),
        implode(', ', $issue['actual'])
    );
}
echo '[unique-ddl] 失败：' . count($issues) . " 个幂等唯一键与迁移 DDL 不一致\n";
exit(1);
