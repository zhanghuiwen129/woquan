<?php
/**
 * 生成「全新安装终态 DDL 基线」api/core/FreshInstallSchema.php
 *
 * 数据源：一个已经跑到终态版本的数据库（版本从 schema_versions 表实读）。
 * 默认用 config.php 的连接参数；也可用命令行覆盖，便于在任意终态库上重建：
 *
 *   php scripts/build_fresh_schema.php
 *   php scripts/build_fresh_schema.php --db=xxx --user=xxx --pass=xxx --host=127.0.0.1 --port=3306
 *
 * 生成规则（可移植性优先）：
 *   1. 只导出 MigrationContract::REQUIRED_TABLES 中的表，不多不少；
 *   2. CREATE TABLE 加 IF NOT EXISTS，便于安装中断后重放；
 *   3. 去掉 AUTO_INCREMENT=N，避免把开发库的自增指针带进新站；
 *   4. 去掉 FULLTEXT KEY 行 —— ngram 分词器是 MySQL 专有语法，MariaDB 上
 *      CREATE TABLE 会直接失败。全文索引统一由 Migration::ensurePostsFulltextIndex()
 *      按数据库能力分级创建（ngram → 普通 FULLTEXT → LIKE 降级）；
 *   5. 不导出任何数据。种子数据仍由迁移代码写入，保持单一数据源。
 *
 * 维护约定：新增 migrateNN 后，除 build_migration_contract.php 外，也要在终态库上重跑本脚本。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/api/core/MigrationContract.php';

use Hublog\Core\MigrationContract;

$opt = getopt('', ['db::', 'user::', 'pass::', 'host::', 'port::', 'out::']);
$dbName = isset($opt['db']) ? (string)$opt['db'] : DB_NAME;
$dbUser = isset($opt['user']) ? (string)$opt['user'] : DB_USER;
$dbPass = isset($opt['pass']) ? (string)$opt['pass'] : DB_PASS;
$dbHost = isset($opt['host']) ? (string)$opt['host'] : DB_HOST;
$dbPort = isset($opt['port']) ? (string)$opt['port'] : DB_PORT;
$outFile = isset($opt['out']) ? (string)$opt['out'] : $root . '/api/core/FreshInstallSchema.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, '数据库连接失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function cleanDdl(string $sql): string
{
    // CREATE TABLE `t` → CREATE TABLE IF NOT EXISTS `t`
    $sql = preg_replace('/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1);
    // 去掉自增指针（保留列定义里的 AUTO_INCREMENT 属性）
    $sql = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $sql);
    // 去掉 FULLTEXT KEY 行（可能带 MySQL 版本注释 /*!50100 WITH PARSER ... */）
    $sql = preg_replace('/^\s*FULLTEXT\s+KEY[^\n]*\n/mi', '', $sql);
    // 删行后可能留下 ",\n)" —— 清成 ")"
    $sql = preg_replace('/,\s*\n\s*\)/', "\n)", $sql);
    return trim($sql);
}

$statements = [];
$missing = [];
foreach (MigrationContract::REQUIRED_TABLES as $table) {
    $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_NUM);
    if (!$row) {
        $missing[] = $table;
        continue;
    }
    $statements[$table] = cleanDdl((string)$row[1]);
}
if ($missing !== []) {
    fwrite(STDERR, '终态库缺表: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$lines = [];
$lines[] = '<?php';
$lines[] = '/**';
$lines[] = ' * 全新安装终态 DDL 基线 —— 由 scripts/build_fresh_schema.php 从终态库生成，勿手工编辑。';
$lines[] = ' *';
$lines[] = ' * 用途：install.php 的全新安装路径一次性建出全部 ' . count($statements) . ' 张表，';
$lines[] = ' *      不再循环执行 66 个 migrateNN（它们在大数据字典服务器上会发出上百次';
$lines[] = ' *      information_schema 探测，实测占安装耗时 90%）。';
$lines[] = ' *';
$lines[] = ' * 注意：';
$lines[] = ' *   - 只含 DDL，不含任何数据；种子仍由迁移代码写入，保持单一数据源；';
$lines[] = ' *   - posts 的 FULLTEXT KEY 已剥离，由 Migration::ensurePostsFulltextIndex() 分级创建；';
$lines[] = ' *   - 新增 migrateNN 后必须在终态库重跑生成器，否则新表不会进入基线。';
$lines[] = ' */';
$lines[] = 'declare(strict_types=1);';
$lines[] = '';
$lines[] = 'namespace Hublog\\Core;';
$lines[] = '';
$lines[] = 'final class FreshInstallSchema';
$lines[] = '{';
// schema 版本从终态库实读，避免每次新增 migrateNN 都要回来手改这里的硬编码。
$schemaVersion = (int)$pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn();
if ($schemaVersion <= 0) {
    fwrite(STDERR, "终态库无 schema_versions 记录，无法确定 SCHEMA_VERSION\n");
    exit(1);
}
$lines[] = '    /** 基线对应的 schema 版本，必须与 Migration::$currentVersion 一致 */';
$lines[] = '    public const SCHEMA_VERSION = ' . $schemaVersion . ';';
$lines[] = '';
$lines[] = '    /** @var array<string,string> 表名 => CREATE TABLE IF NOT EXISTS 语句 */';
$lines[] = '    public const STATEMENTS = ' . str_replace("\n", "\n    ", var_export($statements, true)) . ';';
$lines[] = '}';
$lines[] = '';

$written = file_put_contents($outFile, implode("\n", $lines));
if ($written === false) {
    fwrite(STDERR, '写入失败: ' . $outFile . PHP_EOL);
    exit(1);
}
echo 'OK: ' . $outFile . ' (' . count($statements) . " tables, {$written} bytes)\n";
