<?php
/**
 * 我圈 安装程序
 * 环境检测 + 数据库配置 + 自动导入
 *
 * P1-11 修复：
 * 1. 锁文件移位至 config.php 同级目录（.installed.lock），不再位于 cache/（防清缓存丢锁可重放）
 * 2. 参数校验：db_host（主机名/IP 正则）、db_port（1-65535）、adminUser（用户名正则）、adminPass（强度）
 * 3. Host/Referer 同源校验，防 CSRF 诱导 POST 重放
 * 4. 管理员已存在时不允许直接改密，拒绝重放安装请求接管后台
 * 5. 安装成功后自删 install.php
 * 6. 引入一次性安装 Token（session_start 基础 CSRF），POST 必须携带 install_token
 */

declare(strict_types=1);

$requiredPhp = '8.0.0';
$step = $_GET['step'] ?? 'check';
$error = '';
$success = '';

// S8：锁文件真正移到 config.php 同级的根目录（原来注释写了 .installed.lock，
// 代码却仍指向 cache/install.lock，清缓存即丢锁 → 安装请求可被重放）。
// 路径与「已安装」判定统一由 bootstrap.php 提供，避免三处硬编码再次漂移。
require_once __DIR__ . '/bootstrap.php';
$installLockFile = \Hublog\Bootstrap\installLockPath(__DIR__);

// P3 四入口初始化收敛：公共默认常量的定义时机必须与其余三个入口一致——
// 在 require config.php 之后调用（config.php 优先，默认值仅兜底）。
// 若在此处（config.php 写入前）提前调用，install 阶段 require 新生成的
// config.php 时，其中裸 define() 会因常量已存在而抛出
// "Constant XXX already defined" 警告，且 config.php 里的值不生效。
// 实际调用点见下方 require config.php 之后。

// 自动适配根目录/子目录部署
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
$dir = dirname($scriptName);
$baseUrl = $dir === '/' || $dir === '\\' ? '' : rtrim($dir, '/');

// 检查是否已经安装（同时兼容老部署的 cache/install.lock）
if (\Hublog\Bootstrap\isInstalled(__DIR__) && $step !== 'finish') {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hublog - 已安装</title><style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}.box{background:#fff;padding:40px;border-radius:12px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,0.1);}</style></head><body><div class="box"><h1>Hublog 已安装</h1><p>如需重新安装，请删除根目录下的 <code>' . \Hublog\Bootstrap\INSTALL_LOCK_FILE . '</code> 文件</p><a href="' . $baseUrl . '/" style="color:#07c160;">进入首页</a></div></body></html>';
    exit;
}

// P1-11：安装 Token（基于会话的一次性 CSRF 防护，避免 CSRF 诱导 POST 重放）
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(24));
}
$installToken = $_SESSION['install_token'];

/**
 * P1-11：校验 Host 头与 Referer 是否同源
 */
function hublog_install_check_origin(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        // 本地 CLI 安装时可能无 Host，放行
        return true;
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') {
        // 空 Referer 不强行阻止（用户浏览器隐私模式），但记录警告
        return true;
    }
    $refererHost = parse_url($referer, PHP_URL_HOST);
    $refererPort = parse_url($referer, PHP_URL_PORT);
    $expectHost = parse_url('http://' . $host, PHP_URL_HOST);
    $expectPort = parse_url('http://' . $host, PHP_URL_PORT);
    if ($refererHost === null || $expectHost === null) return true;
    if ($refererHost !== $expectHost) return false;
    if (($refererPort ?: 0) !== ($expectPort ?: 0)) return false;
    return true;
}

function checkExtension(string $name): bool {
    return extension_loaded($name);
}

function checkWritable(string $path): bool {
    return is_writable($path);
}

// 执行安装
if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // P1-11：一次性 Token 校验 + 同源校验
    $postedToken = $_POST['install_token'] ?? '';
    if (!hash_equals($installToken, (string)$postedToken)) {
        $error = '安装 Token 不匹配，可能是表单过期或跨站请求。请返回 config 页刷新重试。';
        $step = 'config';
    } elseif (!hublog_install_check_origin()) {
        $error = '请求来源非本站，已拒绝（跨站安装请求拦截）。';
        $step = 'config';
    } else {
        $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbPort = trim((string)($_POST['db_port'] ?? '3306'));
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $adminPassConfirm = (string)($_POST['admin_pass_confirm'] ?? '');
        $siteName = trim((string)($_POST['site_name'] ?? '我圈'));

        // P1-11：统一参数校验
        if ($dbName === '' || $dbUser === '' || $adminPass === '' || $adminUser === '') {
            $error = '请填写所有必填项';
            $step = 'config';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
            $error = '数据库名只能包含字母、数字和下划线';
            $step = 'config';
        } elseif (!preg_match('/^[a-zA-Z0-9._\-:]+$/', $dbHost)) {
            // 主机名/IPv4/IPv6[:zone]/Unix socket 路径名限定字符
            $error = '数据库主机包含非法字符';
            $step = 'config';
        } elseif (!ctype_digit($dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
            $error = '数据库端口必须是 1-65535 之间的整数';
            $step = 'config';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,19}$/', $adminUser)) {
            $error = '管理员账号必须以字母开头，2-20 位字母/数字/下划线';
            $step = 'config';
        } elseif (strlen($adminPass) < 8) {
            $error = '管理员密码至少 8 位，建议包含大小写、数字与符号';
            $step = 'config';
        } elseif ($adminPass !== $adminPassConfirm) {
            $error = '两次输入的管理员密码不一致';
            $step = 'config';
        } elseif (strlen($siteName) > 100) {
            $error = '站点名称过长（≤100字符）';
            $step = 'config';
        } else {
            try {
                $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // 创建数据库（库名通过严格正则校验 + 反引号包裹，无注入风险）
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                // 强制升级已有数据库的 charset（防止 my.ini 默认 utf8 导致 IF NOT EXISTS 跳过 charset 变更）
                $pdo->exec("ALTER DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                // S8：重放安装请求不能在覆写 config.php 之后才被拦。
                // 原流程先写 config.php（含新 JWT_SECRET 与本次提交的 DB 凭据），
                // 直到 Migration::run() 之后才查已有管理员并拒绝 —— 那时站点配置
                // 已被劫持。现在先用本次建立的连接做一次预检，命中即拒绝，
                // 绝不触碰现有 config.php。
                try {
                    $tables = $pdo->query("SHOW TABLES LIKE 'users'");
                    if ($tables && $tables->fetchColumn() !== false) {
                        $adminCount = (int)$pdo->query(
                            "SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'"
                        )->fetchColumn();
                        if ($adminCount > 0) {
                            throw new \RuntimeException(
                                '目标数据库已存在管理员账号。为防止安装请求被重放覆盖配置与管理员密码，'
                                . 'install.php 已拒绝本次安装。如需重装请先备份数据并移除安装锁。'
                            );
                        }
                    }
                } catch (\RuntimeException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // users 表不存在 / 权限不足 → 按全新安装继续
                    error_log('[Hublog Install] 管理员预检跳过: ' . $e->getMessage());
                }

                // 写入配置文件（后续 Migration 需要读取数据库配置）
                $esc = fn($s) => addcslashes((string)$s, "'\\");
                $jwtSecret = bin2hex(random_bytes(32));
                $configContent = "<?php\n" .
                    "/**\n * Hublog 配置文件\n * 由安装程序自动生成\n */\n\n" .
                    "declare(strict_types=1);\n\n" .
                    "define('DB_HOST', '{$esc($dbHost)}');\n" .
                    "define('DB_PORT', '{$esc($dbPort)}');\n" .
                    "define('DB_NAME', '{$esc($dbName)}');\n" .
                    "define('DB_USER', '{$esc($dbUser)}');\n" .
                    "define('DB_PASS', '{$esc($dbPass)}');\n" .
                    "define('DB_CHARSET', 'utf8mb4');\n\n" .
                    "define('APP_NAME', '{$esc($siteName)}');\n" .
                    "define('APP_VERSION', 'v2.16.29');\n" .
                    "define('APP_DEBUG', false);\n" .
                    "define('APP_URL', '');\n" .
                    "define('TIMEZONE', 'Asia/Shanghai');\n\n" .
                    "define('JWT_SECRET', '{$jwtSecret}');\n" .
                    "define('JWT_EXPIRE', 86400 * 7);\n" .
                    "define('CSRF_TOKEN_EXPIRE', 3600);\n" .
                    "define('PASSWORD_COST', 12);\n" .
                    "define('RATE_LIMIT_REQUESTS', 100);\n" .
                    "define('RATE_LIMIT_WINDOW', 60);\n\n" .
                    "define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);\n" .
                    "define('UPLOAD_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);\n" .
                    "define('UPLOAD_AVATAR_DIR', __DIR__ . '/uploads/avatars/');\n" .
                    "define('UPLOAD_IMAGE_DIR', __DIR__ . '/uploads/images/');\n" .
                    "define('UPLOAD_VIDEO_DIR', __DIR__ . '/uploads/videos/');\n" .
                    "define('UPLOAD_ATTACHMENT_DIR', __DIR__ . '/uploads/attachments/');\n" .
                    "define('UPLOAD_ATTACHMENT_EXTS', ['zip', 'rar', '7z', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt']);\n" .
                    "define('UPLOAD_BLOCKED_IMAGE_URL', 'assets/images/blocked.svg');\n" .
                    "define('UPLOAD_URL_PATH', '/uploads/');\n\n" .
                    "define('CACHE_DRIVER', 'file');\n" .
                    "define('CACHE_PREFIX', 'hublog_');\n" .
                    "define('CACHE_TTL', 3600);\n\n" .
                    "define('DEFAULT_TEMPLATE', 'moments');\n" .
                    "define('TEMPLATE_DIR', __DIR__ . '/templates/');\n\n" .
                    "define('TRUSTED_PROXIES', '127.0.0.1,::1');\n\n" .
                    "if (!APP_DEBUG) {\n    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);\n    ini_set('display_errors', '0');\n    ini_set('log_errors', '1');\n} else {\n    error_reporting(E_ALL);\n    ini_set('display_errors', '1');\n}\n\n" .
                    "date_default_timezone_set(TIMEZONE);\n";

                file_put_contents(__DIR__ . '/config.php', $configContent);
                // 限制 config.php 仅本进程可读，防止同主机其他用户窃取 JWT_SECRET 与 DB 密码
                @chmod(__DIR__ . '/config.php', 0600);

                // 加载配置和自动加载器，调用 Migration 完成全部建表
                require_once __DIR__ . '/config.php';
                // 与 index.php / api/index.php / reconcile-counters.php 同序：
                // config.php 已定义的常量优先，这里只为缺失的常量兜底，避免重复 define 警告。
                \Hublog\Bootstrap\defineDefaults();
                require_once __DIR__ . '/api/core/AutoLoader.php';
                \Hublog\Core\AutoLoader::register();

                // 安装过程需要执行大量 SQL（建表 + 索引 + 默认数据），禁用执行时间限制并提升内存，
                // 避免 PHP-FPM request_terminate_timeout 导致 502 Bad Gateway
                @set_time_limit(0);
                @ini_set('memory_limit', '256M');
                @ini_set('max_execution_time', '0');

                // 清空版本 / 已验证 / 失败标记，强制全新安装走 buildFullSchema() 全量建表快速路径。
                // 只删 .schema_version 是不够的：残留的 .schema_verified 会让 run() 直接走
                // 稳态快路径返回，装完却没建表（迁移治理修复）。
                \Hublog\Core\Migration::resetState();

                // 执行迁移（全新安装无 users 表 + 无版本文件，run() 会走 buildFullSchema()
                // 一次性创建全部 20 张表 + 默认配置 + ai_assistant 用户（id=2），跳过 30 个增量迁移）
                \Hublog\Core\Migration::run();

                // P1-11：管理员插入前先判断 users 表是否有 admin 角色记录
                // - 0 个管理员（全新安装）→ 正常插入 id=1
                // - ≥1 个管理员（重放安装请求）→ 直接拒绝，防止通过 install.php 覆盖现有管理员密码接管后台
                $existingAdminCount = (int)\Hublog\Core\Database::fetchColumn(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
                );
                $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $existingByName = \Hublog\Core\Database::fetchColumn(
                    "SELECT id FROM users WHERE username = ? LIMIT 1",
                    [$adminUser]
                );

                if ($existingAdminCount > 0) {
                    throw new \RuntimeException(
                        '检测到数据库中已存在管理员账号。为防止安装请求被重放覆盖管理员密码，install.php 已拒绝本次写入。'
                        . ' 如需强制重置管理员密码，请登录后台操作或手动修改 config.php 中 JWT_SECRET 后使用数据库工具重置密码。'
                    );
                }

                // 全新安装：显式插入管理员为 id=1（ai_assistant 已是 id=2）
                $db = \Hublog\Core\Database::getInstance();
                $db->exec(
                    "INSERT INTO `users` (`id`, `username`, `password`, `email`, `nickname`, `role`, `status`, `token_version`, `created_at`, `updated_at`) "
                    . "VALUES (1, " . $db->quote($adminUser)
                    . ", " . $db->quote($hash)
                    . ", 'admin@localhost', '管理员', 'admin', 1, 1, NOW(), NOW())"
                );
                // 重置自增指针，避免后续插入冲突（id=1 管理员，id=2 ai_assistant）
                $db->exec("ALTER TABLE `users` AUTO_INCREMENT = 3");

                // 更新站点名称
                \Hublog\Core\Database::update('settings', ['value' => $siteName], "`key` = 'site_name'");

                // S8：生成根目录 .installed.lock（含安装时间/token/PHP 版本/App 版本），
                // 不再放 cache/ 下；Web 服务器需拒绝访问 .installed.lock（见四套配置）。
                file_put_contents(
                    $installLockFile,
                    json_encode([
                        'installed_at' => date('Y-m-d H:i:s'),
                        'token' => bin2hex(random_bytes(16)),
                        'php_version' => PHP_VERSION,
                        'app_version' => 'v2.0',
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    LOCK_EX
                );
                @chmod($installLockFile, 0600);

                // 授权统计上报：首次握手（失败完全静默，不渲染任何提示、不阻断安装完成）。
                // getOrCreateId() 在此生成并持久化 installation_id，随后立即上报一次全量元数据。
                try {
                    \Hublog\Core\HeartbeatReporter::reportNow();
                } catch (\Throwable $e) {
                    error_log('[Hublog Install] 首次授权上报失败（已忽略）: ' . $e->getMessage());
                }

                // P1-11：安装成功后自删 install.php，阻止重复执行
                // 如果删除失败，写入提示文件并记录日志
                $selfDeleted = @unlink(__FILE__);
                if (!$selfDeleted) {
                    error_log('[Hublog Install] 警告：install.php 无法自删（权限不足），请手动删除 ' . __FILE__ . ' 以避免被重放利用。');
                }

                // 失效 install_token（一次性）
                $_SESSION['install_token'] = null;

                $success = '安装成功！' . ($selfDeleted ? '' : '（install.php 未自动删除，请登录后手动移除）');
                $step = 'finish';
            } catch (PDOException $e) {
                $error = '数据库连接失败: ' . $e->getMessage();
                $step = 'config';
            } catch (Throwable $e) {
                $error = '安装失败: ' . $e->getMessage();
                $step = 'config';
            }
        }
    }
}

$phpOk = version_compare(PHP_VERSION, $requiredPhp, '>=');
$pdoOk = checkExtension('pdo');
$pdoMysqlOk = checkExtension('pdo_mysql');
$gdOk = checkExtension('gd');
$jsonOk = checkExtension('json');
$fileinfoOk = checkExtension('fileinfo');
// fileinfo 为推荐扩展（用于上传 MIME 校验），缺失时降级为扩展名+文件头校验，不阻断安装
$allOk = $phpOk && $pdoOk && $pdoMysqlOk && $gdOk && $jsonOk;

$dirs = ['uploads', 'uploads/avatars', 'uploads/images', 'uploads/videos', 'uploads/attachments', 'cache', 'logs', 'backups'];
$dirStatus = [];
foreach ($dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) mkdir($path, 0755, true);
    $dirStatus[$dir] = checkWritable($path);
}
$allWritable = !in_array(false, $dirStatus, true);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我圈 安装程序</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f5f5f5; display:flex; justify-content:center; align-items:flex-start; min-height:100vh; padding:40px 20px; }
        .container { width:100%; max-width:600px; }
        .logo { text-align:center; margin-bottom:30px; }
        .logo h1 { font-size:28px; color:#07c160; }
        .logo p { color:#999; margin-top:6px; }
        .card { background:#fff; border-radius:12px; padding:28px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.08); }
        .card h2 { font-size:18px; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #eee; }
        .check-item { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f5f5f5; }
        .check-item:last-child { border-bottom:none; }
        .status { font-size:13px; padding:2px 10px; border-radius:4px; }
        .status.ok { background:#f6ffed; color:#52c41a; }
        .status.fail { background:#fff1f0; color:#ff4d4f; }
        .status.warning { background:#fffbe6; color:#faad14; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; margin-bottom:6px; font-size:14px; font-weight:500; }
        .form-group label .required { color:#ff4d4f; }
        .form-input { width:100%; padding:10px 12px; border:1px solid #d9d9d9; border-radius:6px; font-size:14px; transition:border-color 0.2s; }
        .form-input:focus { outline:none; border-color:#07c160; }
        .btn { width:100%; padding:12px; background:#07c160; color:#fff; border:none; border-radius:6px; font-size:15px; cursor:pointer; transition:opacity 0.2s; }
        .btn:hover { opacity:0.9; }
        .btn:disabled { background:#ccc; cursor:not-allowed; }
        .alert { padding:12px 16px; border-radius:6px; margin-bottom:20px; font-size:14px; }
        .alert-error { background:#fff1f0; color:#ff4d4f; border:1px solid #ffccc7; }
        .alert-success { background:#f6ffed; color:#52c41a; border:1px solid #d9f7be; }
        .step-bar { display:flex; margin-bottom:24px; }
        .step { flex:1; text-align:center; padding:12px; font-size:14px; color:#999; position:relative; }
        .step.active { color:#07c160; font-weight:600; }
        .step::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:#eee; }
        .step.active::after { background:#07c160; }
        .finish-box { text-align:center; padding:40px 20px; }
        .finish-box .icon { font-size:64px; margin-bottom:16px; }
        .finish-box h2 { margin-bottom:12px; }
        .finish-box p { color:#666; margin-bottom:24px; }
        .finish-box a { display:inline-block; padding:10px 32px; background:#07c160; color:#fff; text-decoration:none; border-radius:6px; }
    </style>
</head>
<body>
<div class="container">
    <div class="logo">
        <h1>我圈</h1>
        <p>轻量级多用户社区博客系统 V1.0</p>
    </div>

    <div class="step-bar">
        <div class="step <?php echo $step==='check'?'active':''; ?>">环境检测</div>
        <div class="step <?php echo $step==='config'?'active':''; ?>">数据库配置</div>
        <div class="step <?php echo $step==='finish'?'active':''; ?>">安装完成</div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($step === 'check'): ?>
    <div class="card">
        <h2>环境检测</h2>
        <div class="check-item">
            <span>PHP 版本 (>= 8.0)</span>
            <span class="status <?php echo $phpOk?'ok':'fail'; ?>"><?php echo $phpOk?'通过 ('.PHP_VERSION.')':'失败 ('.PHP_VERSION.')'; ?></span>
        </div>
        <div class="check-item">
            <span>PDO 扩展</span>
            <span class="status <?php echo $pdoOk?'ok':'fail'; ?>"><?php echo $pdoOk?'已安装':'未安装'; ?></span>
        </div>
        <div class="check-item">
            <span>PDO_MySQL 扩展</span>
            <span class="status <?php echo $pdoMysqlOk?'ok':'fail'; ?>"><?php echo $pdoMysqlOk?'已安装':'未安装'; ?></span>
        </div>
        <div class="check-item">
            <span>GD 扩展</span>
            <span class="status <?php echo $gdOk?'ok':'fail'; ?>"><?php echo $gdOk?'已安装':'未安装'; ?></span>
        </div>
        <div class="check-item">
            <span>JSON 扩展</span>
            <span class="status <?php echo $jsonOk?'ok':'fail'; ?>"><?php echo $jsonOk?'已安装':'未安装'; ?></span>
        </div>
        <div class="check-item">
            <span>Fileinfo 扩展 <small style="color:#999;">(推荐)</small></span>
            <span class="status <?php echo $fileinfoOk?'ok':'warning'; ?>"><?php echo $fileinfoOk?'已安装':'未安装(可继续)'; ?></span>
        </div>
        <?php if (!$fileinfoOk): ?>
        <div style="margin:-8px 0 8px; padding:8px 12px; background:#fffbe6; border-radius:4px; font-size:12px; color:#ad6800; line-height:1.6;">
            Fileinfo 扩展未安装时，图片上传将降级为文件头+GD 双重校验，升级包上传降级为扩展名校验，核心功能不受影响。建议生产环境安装此扩展以增强安全性。
        </div>
        <?php endif; ?>
        <?php foreach ($dirStatus as $dir => $writable): ?>
        <div class="check-item">
            <span>目录权限: <?php echo $dir; ?>/</span>
            <span class="status <?php echo $writable?'ok':'fail'; ?>"><?php echo $writable?'可写':'不可写'; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <button class="btn" <?php echo (!$allOk || !$allWritable)?'disabled':''; ?> onclick="location.href='?step=config'">
        <?php echo (!$allOk || !$allWritable)?'请修复上述问题后继续':'下一步：配置数据库' ?>
    </button>

    <?php elseif ($step === 'config'): ?>
    <div class="card">
        <h2>数据库配置</h2>
        <form method="POST" action="?step=install">
            <input type="hidden" name="install_token" value="<?php echo htmlspecialchars($installToken); ?>">
            <div class="form-group">
                <label>数据库主机 <span class="required">*</span></label>
                <input type="text" class="form-input" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>数据库端口</label>
                <input type="text" class="form-input" name="db_port" value="3306">
            </div>
            <div class="form-group">
                <label>数据库名称 <span class="required">*</span></label>
                <input type="text" class="form-input" name="db_name" placeholder="hublog" required>
            </div>
            <div class="form-group">
                <label>数据库用户名 <span class="required">*</span></label>
                <input type="text" class="form-input" name="db_user" placeholder="root" required>
            </div>
            <div class="form-group">
                <label>数据库密码</label>
                <input type="password" class="form-input" name="db_pass" placeholder="">
            </div>
            <div class="form-group">
                <label>站点名称</label>
                <input type="text" class="form-input" name="site_name" value="我圈">
            </div>
            <div class="form-group">
                <label>管理员账号 <span class="required">*</span></label>
                <input type="text" class="form-input" name="admin_user" value="admin" required>
            </div>
            <div class="form-group">
                <label>管理员密码 <span class="required">*</span></label>
                <input type="password" class="form-input" name="admin_pass" placeholder="请设置管理员密码" required>
            </div>
            <div class="form-group">
                <label>确认密码 <span class="required">*</span></label>
                <input type="password" class="form-input" name="admin_pass_confirm" placeholder="请再次输入管理员密码" required>
            </div>
            <button type="submit" class="btn">开始安装</button>
        </form>
    </div>

    <?php elseif ($step === 'finish'): ?>
    <div class="card finish-box">
        <div class="icon">🎉</div>
        <h2>安装成功</h2>
        <p>我圈 已成功安装，请登录后台进行管理。</p>
        <p style="font-size:13px;color:#999;margin-top:8px;">管理员账号: <?php echo htmlspecialchars($_POST['admin_user'] ?? 'admin'); ?></p>
        <a href="<?php echo $baseUrl; ?>/">进入首页</a>
        <p style="margin-top:16px;font-size:12px;color:#999;">已生成根目录 .installed.lock 锁文件防止重复安装，如需重新安装请删除该文件</p>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
