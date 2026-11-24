<?php
/**
 * 隔离子进程 fixture：在 APP_DEBUG=true 下真实调用 PhoneCodeService::issue()。
 *
 * 为什么必须独立进程：api/tests/bootstrap.php 把 APP_DEBUG 定义为 false 且常量不可改，
 * 而「仅 DEBUG 回带 dev_code」这条分支必须验证，否则 S1 修复只测了一半。
 *
 * 本脚本刻意不 require PHPUnit / tests/bootstrap.php，只加载生产代码 + 最小常量桩，
 * 保证 php scripts/ci.php 的子进程与 CI 环境都能无依赖执行。
 * 输出：单行 JSON，由 BehaviorTestCase::runIsolated() 解析。
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);

define('APP_DEBUG', true);
define('APP_NAME', 'Hublog');
define('JWT_SECRET', 'behavior-fixture-secret');
define('JWT_EXPIRE', 86400);
define('CACHE_TTL', 3600);
define('CACHE_DRIVER', 'file');
define('RATE_LIMIT_REQUESTS', 300);
define('RATE_LIMIT_WINDOW', 60);
define('TRUSTED_PROXIES', '127.0.0.1,::1');

require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE `phone_codes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `phone` TEXT NOT NULL, `code` TEXT NOT NULL, `scene` TEXT NOT NULL,
    `expires_at` TEXT NOT NULL, `sent_ip` TEXT,
    `created_at` TEXT NOT NULL DEFAULT (datetime('now','localtime'))
)
SQL);
$pdo->exec('CREATE TABLE `settings` (`key` TEXT PRIMARY KEY, `value` TEXT)');
$pdo->exec("INSERT INTO `settings` (`key`,`value`) VALUES ('sms_provider','none')");

$inject = static function (string $class, string $property, mixed $value): void {
    $ref = new ReflectionProperty($class, $property);
    $ref->setAccessible(true);
    $ref->setValue(null, $value);
};
$inject(\Hublog\Core\Database::class, 'instance', $pdo);
$inject(\Hublog\Core\Cache::class, 'redis', null);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hublog_fixture_' . bin2hex(random_bytes(5));
@mkdir($dir, 0777, true);
$inject(\Hublog\Core\Cache::class, 'cacheDir', $dir . DIRECTORY_SEPARATOR);
\Hublog\Core\Cache::flush();

$result = \Hublog\Core\PhoneCodeService::issue('13800138000', 'login', '203.0.113.9');
$row = $pdo->query('SELECT `code` FROM `phone_codes` ORDER BY `id` DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
$dbCode = (string)($row['code'] ?? '');
$devCode = (string)($result['data']['dev_code'] ?? '');

// V102：库里存 bcrypt 哈希，dev_code 是明文。用 password_verify 验证两者对应，
// 同时暴露 db_code 本身供测试断言「不是明文 6 位」。
echo json_encode([
    'result'             => $result,
    'db_code'            => $dbCode,
    'db_code_is_hash'    => str_starts_with($dbCode, '$2y$'),
    'db_code_matches_dev'=> $devCode !== '' && password_verify($devCode, $dbCode),
    'debug'              => APP_DEBUG,
], JSON_UNESCAPED_UNICODE), "\n";

// 清理子进程自己的临时缓存目录
foreach (glob($dir . '/*') ?: [] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
@rmdir($dir);
