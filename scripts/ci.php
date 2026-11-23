<?php
/**
 * Hublog CI 门禁（平台无关、零依赖）
 *
 * 任何 CI（GitHub Actions / Gitee Go / Jenkins / 宝塔钩子）或本机只需一行：
 *     php scripts/ci.php
 * 也可用 composer ci 调用。加 --lint-only 只跑语法检查（快速预提交钩子）。
 *
 * 为什么是 PHP 脚本而不是 shell：
 *   - Windows / Linux 都能跑，不依赖 bash、find、xargs；
 *   - 不依赖 composer、不依赖数据库，缺依赖时给出明确提示而不是静默跳过；
 *   - 检查逻辑随仓库版本化，流水线 YAML 只负责装 PHP + composer。
 *
 * 门禁项：
 *   1. PHP 版本与测试必需扩展
 *   2. 敏感文件不得入库（config.php / .env / .installed.lock / 密钥）
 *   3. composer.json / composer.lock 一致性（零依赖 content-hash 复算 + composer validate 增强）
 *   4. 全量 PHP 语法检查（php -l）
 *   5. 自包含回归护栏（api/tests/run_tests.php，无 vendor 也能跑）+ 迁移终态契约一致性
 *      （新增表/列忘了重生成契约 = 自检漏登 = 半残库，必须在 CI 里拦住）
 *   6. PHPUnit behavior + unit 套件（需要 vendor；缺失即失败，不静默降级）
 *      behavior 用 SQLite 内存库跑真实业务行为，需要 pdo_sqlite 扩展。
 *   6.5 PHPStan 静态分析（level 3，未安装=缺工具=门禁失败；CI 需先跑 install-phpstan.php）
 *   6.6 前台主题 DOM 契约审计（有 node 才跑；主题 HTML 漏 JS 强依赖 id/class = 阻断）
 *   6.7 静态审计与巡检：后台 JS / 鉴权守卫 / 裸 error_log / 控制器内联 SQL 为硬门禁；
 *       幂等键缺键阻断、环境缺失降级 WARN；运行时体检与主题同步
 *       （check-theme-sync.js 假阳性较多）暂按 WARN 接入。
 *   6.8 路由 / OpenAPI 对账：parse_routes.php 零依赖解析真实路由，
 *       check_route_docs.php 用冻结基线拦「新增路由未入文档」与差异扩大。
 *
 * 退出码：0 = 全通过，1 = 存在失败项。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$php  = PHP_BINARY;

$onlyLint = in_array('--lint-only', $argv, true);

/** @var list<string> $failures */
$failures = [];
/** @var list<string> $warnings */
$warnings = [];

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

/**
 * 执行命令并拿到退出码。
 *
 * 优先用 proc_open（数组 argv，不经过 shell，Windows 引号不会被吞）。
 * 但宝塔 / 共享主机常在 disable_functions 里禁掉 proc_open，
 * 所以必须提供 exec 回退——门禁脚本本身不能成为「只能在我机器上跑」的东西。
 */
function run(array $cmd, string $cwd, string &$out): int
{
    if (function_exists('proc_open')) {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $out  = trim($stdout . "\n" . $stderr);
            return $code;
        }
    }

    if (!function_exists('exec')) {
        $out = 'proc_open 与 exec 均不可用，无法执行子进程: ' . implode(' ', $cmd);
        return 127;
    }

    $line  = implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1';
    $old   = getcwd();
    if (is_dir($cwd)) {
        @chdir($cwd);
    }
    $lines = [];
    $code  = 0;
    @exec($line, $lines, $code);
    if ($old !== false) {
        @chdir($old);
    }
    $out = trim(implode("\n", $lines));
    return $code;
}

function report(string $name, bool $ok, string $detail = '', ?string $warn = null): void
{
    global $failures, $warnings;
    if (!$ok) {
        $failures[] = $name;
        echo "[FAIL] {$name}\n";
        if ($detail !== '') {
            echo rtrim($detail) . "\n";
        }
        return;
    }
    if ($warn !== null) {
        $warnings[] = $name . ': ' . $warn;
        echo "[WARN] {$name} — {$warn}\n";
        return;
    }
    echo "[PASS] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** 失败时只截尾部，避免刷屏 */
function tail(string $s, int $lines = 25): string
{
    $a = preg_split('/\R/', trim($s)) ?: [];
    return implode("\n", array_slice($a, -$lines));
}

// ── 1. 运行时 ────────────────────────────────────────────────────────────
section('1/6 运行时');
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
report('PHP >= 8.1', $phpOk, $phpOk ? PHP_VERSION : '当前 ' . PHP_VERSION . '，PHPUnit 10.5 需要 PHP >= 8.1');

$needExt = ['dom', 'json', 'libxml', 'mbstring', 'openssl', 'pdo_sqlite', 'tokenizer', 'xml', 'xmlwriter'];
$missing = array_values(array_filter($needExt, fn($e) => !extension_loaded($e)));
report('测试必需扩展', $missing === [], $missing === [] ? implode(', ', $needExt) : '缺失: ' . implode(', ', $missing));

// ── 2. 敏感文件不得入库 ──────────────────────────────────────────────────
section('2/6 敏感文件');
$out = '';
$gitCode = run(['git', 'ls-files'], $root, $out);
if ($gitCode !== 0) {
    // 部分 CI 以源码包方式检出、没有 .git 目录，此时降级为警告而不是拖垮门禁。
    report('git ls-files', true, '', '拿不到 git 索引（源码包检出），跳过敏感文件检查');
} else {
    $tracked = preg_split('/\R/', trim($out)) ?: [];
    $forbidden = ['config.php', '.env', '.installed.lock', 'cache/install.lock'];
    $leaked = [];
    foreach ($tracked as $f) {
        $f = str_replace('\\', '/', $f);
        foreach ($forbidden as $bad) {
            if ($f === $bad || str_ends_with($f, '/' . $bad)) {
                $leaked[] = $f;
            }
        }
        if (preg_match('/\.(keystore|pem|key)$/i', $f) && !str_starts_with($f, 'api/tests/fixtures/')) {
            $leaked[] = $f;
        }
    }
    report(
        '凭据/锁文件未被跟踪',
        $leaked === [],
        $leaked === [] ? 'config.php / .env / .installed.lock 均未入库' : '发现已跟踪的敏感文件: ' . implode(', ', array_unique($leaked))
    );
}

if ($onlyLint) {
    // 快速模式仍需跑语法检查
}

// ── 3. 依赖一致性 ───────────────────────────────────────────────────────
if (!$onlyLint) {
    section('3/6 依赖一致性');

    // 3a. 零依赖主校验：复算 composer.json 的 content-hash 与 composer.lock 比对。
    //     算法与 Composer 2.x 的 Composer\Package\Locker::getContentHash 完全一致：
    //     取指定键 → 补 config.platform / config.policy → ksort → json_encode(options=0) → md5。
    //     这样「改了 composer.json 忘了 composer update --lock」在任何环境都能被拦住，
    //     不必依赖 composer 可执行文件（宝塔常禁用 proc_open / proc_get_status）。
    $json = json_decode((string)@file_get_contents($root . '/composer.json'), true);
    $lock = json_decode((string)@file_get_contents($root . '/composer.lock'), true);
    if (!is_array($json) || !is_array($lock)) {
        report('composer.json / lock 可解析', false, '文件缺失或不是合法 JSON');
    } else {
        $relevantKeys = [
            'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
            'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
        ];
        $relevant = array_intersect_key($json, array_flip($relevantKeys));
        foreach (['platform', 'policy'] as $cfgKey) {
            if (isset($json['config'][$cfgKey])) {
                $relevant['config'][$cfgKey] = $json['config'][$cfgKey];
            }
        }
        ksort($relevant);
        $calc   = md5((string)json_encode($relevant, 0));
        $locked = (string)($lock['content-hash'] ?? '');
        report(
            'lock content-hash 一致',
            $calc === $locked,
            $calc === $locked
                ? 'composer.json 与 composer.lock 匹配'
                : "composer.json 已变更但 lock 未更新（算得 {$calc}，lock 为 {$locked}）。请执行 composer update --lock"
        );
    }

    // 3b. 增强校验：composer validate。Symfony Process 同时依赖 proc_open 与
    //     proc_get_status；只解禁前者会让 composer 直接 Fatal，所以环境不完整时
    //     明确降级为 WARN，不让整条门禁因工具链环境假红（主校验已覆盖关键风险）。
    $missingProcFns = array_values(array_filter(
        ['proc_open', 'proc_get_status'],
        fn($f) => !function_exists($f)
    ));

    if ($missingProcFns !== []) {
        report(
            'composer validate',
            true,
            '',
            'disable_functions 仍禁用 ' . implode(', ', $missingProcFns)
                . '，composer 无法运行；已由上面的 content-hash 校验覆盖'
        );
    } else {
        $composer = null;
        $envBin = getenv('COMPOSER_BIN');
        if ($envBin && is_file($envBin)) {
            $composer = [$php, $envBin];
        } elseif (is_file($root . '/composer.phar')) {
            $composer = [$php, $root . '/composer.phar'];
        } else {
            $out = '';
            if (run(['composer', '--version'], $root, $out) === 0) {
                $composer = ['composer'];
            }
        }

        if ($composer === null) {
            report('composer 可用性', true, '', '未找到 composer，跳过 validate（hash 校验已覆盖）');
        } else {
            $out = '';
            $code = run([...$composer, 'validate', '--no-check-publish', '--no-check-all'], $root, $out);
            if ($code !== 0 && (str_contains($out, 'proc_get_status') || str_contains($out, 'proc_open'))) {
                // 子进程可能与当前进程读到不同的 ini（例如 CLI 用 -d 覆盖但未透传）。
                // 这种 Fatal 是工具链环境问题，不是 composer.json 有错，降级为 WARN；
                // 关键风险已由上面的零依赖 hash 校验覆盖。
                report('composer validate', true, '', 'composer 子进程受 disable_functions 限制而失败，已跳过；hash 校验已覆盖');
            } else {
                report('composer validate', $code === 0, $code === 0 ? 'composer.json 合法且 lock 一致' : tail($out));
            }
        }
    }
}

// ── 4. 全量 PHP 语法 ─────────────────────────────────────────────────────
section('4/6 全量语法检查');
$phpFiles = [];
// tmp/ 是被 .gitignore 排除的本地调试目录（探针脚本、trace 文件），不属于交付代码，
// 其中常有意写的语法错误探针（如混用命名空间声明）不应让全量语法门禁变红。
$skipDirs = ['/.git/', '/vendor/', '/.workbuddy/', '/node_modules/', '/coverage/', '/reports/', '/tmp/', '/backups/'];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $info) {
    if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $info->getPathname());
    $skip = false;
    foreach ($skipDirs as $d) {
        if (str_contains($path, $d)) {
            $skip = true;
            break;
        }
    }
    if (!$skip) {
        $phpFiles[] = $path;
    }
}
sort($phpFiles, SORT_STRING);

$syntaxBad = [];
foreach ($phpFiles as $f) {
    $out = '';
    $code = run([$php, '-l', $f], $root, $out);
    if ($code !== 0 || !str_contains($out, 'No syntax errors')) {
        $syntaxBad[] = $f . ' :: ' . tail($out, 3);
    }
}
report(
    'php -l 全量',
    $syntaxBad === [],
    $syntaxBad === [] ? count($phpFiles) . ' 个 PHP 文件' : implode("\n", array_slice($syntaxBad, 0, 20))
);

if ($onlyLint) {
    echo "\n[lint-only] 跳过测试阶段。\n";
    echo "\n" . (($failures === []) ? "门禁通过（仅语法）\n" : "门禁失败: " . implode(', ', $failures) . "\n");
    exit($failures === [] ? 0 : 1);
}

// ── 5. 自包含护栏 + 迁移契约（无 vendor 亦可运行） ────────────────────────
section('5/6 自包含回归护栏 + 迁移契约');
$out = '';
$code = run([$php, $root . '/api/tests/run_tests.php'], $root, $out);
report('api/tests/run_tests.php', $code === 0, $code === 0 ? tail($out, 3) : tail($out, 30));

// 迁移治理：契约由源码生成，漏跑生成器会让新增表/列逃过终态自检。
$out = '';
$code = run([$php, $root . '/scripts/build_migration_contract.php', '--check'], $root, $out);
report('迁移契约与源码一致', $code === 0, $code === 0 ? tail($out, 2) : tail($out, 10));

// ── 6. PHPUnit：行为级 + 结构性套件 ─────────────────────────────────────
section('6/6 PHPUnit（behavior + unit）');
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
    report('vendor/bin/phpunit', false, '缺少 vendor/。请先执行: php composer.phar install （或 composer install）');
} else {
    foreach (['behavior', 'unit'] as $suite) {
        $out = '';
        $code = run(
            [$php, $phpunit, '-c', $root . '/phpunit.xml.dist', '--testsuite', $suite, '--no-coverage'],
            $root,
            $out
        );
        report(
            'PHPUnit ' . $suite . ' 套件',
            $code === 0,
            $code === 0 ? tail($out, 3) : tail($out, 35)
        );
    }
}

// ── 6.5 PHPStan 静态分析（level 3，硬门禁） ────────────────────────────────
// P1-9：PHPStan 从 level 1 提到 level 3，并从「非阻断」改为「阻断」。
// 提级立刻揪出了多处真实缺陷 —— 这些都属于「线上必 500 / 功能形同虚设」：
//   · PDO::exec() 多传第 2 个参数 → ArgumentCountError（头像设置、封面设置、
//     忘记密码、投票移除选项、批量删上传记录 五条路径原本必然 500）；
//   · `new Exception\HublogException` 在 Hublog\Controllers 下解析到不存在的类 → fatal；
//   · Request::getJson() / Upgrade::createBackup() 调用不存在的方法 → fatal；
//   · PointController::currentPoints() 静态调用实例方法 → fatal；
//   · 昵称禁用词检查用了未定义变量 $settings → 禁用词检查完全失效。
// 装法：php scripts/install-phpstan.php（独立 phar，tools/ 不入库）
$phpstan = null;
foreach ([$root . '/tools/phpstan.phar', $root . '/vendor/bin/phpstan'] as $candidate) {
    if (is_file($candidate)) {
        $phpstan = $candidate;
        break;
    }
}
if ($phpstan === null) {
    // 缺工具 = 门禁失败：避免 CI 未装 phar 时静态分析被静默跳过（空转）。
    // 本地 / CI 均须先执行 php scripts/install-phpstan.php。
    $failures[] = 'PHPStan 未安装（php scripts/install-phpstan.php）';
    echo "[FAIL] PHPStan — 未安装，门禁失败（请先执行 php scripts/install-phpstan.php）\n";
} else {
    $cmd = [
        $php,
        $phpstan,
        'analyse',
        '--no-progress',
        '--error-format=raw',
        '--configuration=' . $root . '/phpstan.neon',
        // 提级到 level 3 + 新增框架层文件后，默认 128M 会在分析途中 OOM
        // （stderr 会打印 "process crashed"，但退出码仍非 0，被误报成"发现 N 处问题"）。
        '--memory-limit=1G',
    ];
    // Windows 下 PHPStan 并行模式会 spawn 子进程并调用 wmic/reg（常被安全策略拦截），
    // --debug 走单进程分析，代价是慢一点。
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd[] = '--debug';
    }
    $out = '';
    $code = run($cmd, $root, $out);
    $errCount = 0;
    if ($out !== '') {
        $errCount = count(array_filter(preg_split('/\R/', trim($out)) ?: [], static fn($l) => $l !== ''));
    }
    if ($code === 0) {
        echo "[PASS] PHPStan (level 3) — 无错误\n";
    } else {
        $failures[] = "PHPStan (level 3) 发现 {$errCount} 处问题";
        echo "[FAIL] PHPStan (level 3) — 发现 {$errCount} 处问题\n";
        echo tail($out, 25) . "\n";
    }
}

// ── 6.6 前台主题 DOM 契约审计（有 node 才跑，缺 node 不破坏零依赖承诺） ──
// 新增/修改主题后，若 HTML 漏了 JS 强依赖的关键 id/class，某些功能会静默失效。
// contract.json 由 `node scripts/audit-theme.js --gen` 生成；审计失败视为硬伤（阻断）。
$auditScript = $root . '/scripts/audit-theme.js';
$nodeProbe = '';
$nodeOk = is_file($auditScript) && run(['node', '--version'], $root, $nodeProbe) === 0;
if (!$nodeOk) {
    $warnings[] = 'Node.js 未安装或 audit-theme.js 缺失 — 跳过前台主题 DOM 契约审计';
    echo "[WARN] 主题契约审计 — node 不可用，跳过（有 node 时会自动跑 scripts/audit-theme.js）\n";
} else {
    $out = '';
    $code = run(['node', $auditScript], $root, $out);
    if ($code === 0) {
        echo "[PASS] 前台主题 DOM 契约审计 — 全部主题关键契约完整\n";
    } else {
        $failures[] = '前台主题 DOM 契约审计（audit-theme.js）';
        echo "[FAIL] 前台主题 DOM 契约审计：\n" . tail($out, 30) . "\n";
    }
}

// ── 6.7 静态审计与巡检脚本 ───────────────────────────────────────────────
// 这些脚本此前「写好了但零接线」，等于护栏形同虚设。此处按各自退出码语义接入：
//   · audit-admin-js / audit-auth-guards / check-log-usage / check-inline-sql /
//     check-unique-index-ddl：0=通过、非 0=发现硬伤 → 硬门禁；
//     （check-log-usage 扫业务代码裸 error_log，白名单：迁移脚本 / Cache.php / Logger.php /
//      单行 @allow-error-log 豁免；--list 恒 0 仅供人工审查，CI 走默认模式；
//      check-inline-sql 扫控制器中新增的 Database:: 静态调用与 paginate() SQL 字面量，
//      已收敛文件严格上限为 0；
//      check-unique-index-ddl 纯源码比对迁移 DDL 与契约的列清单，不连库，
//      是 CI 可达的构建期硬门禁）；
//   · check-idempotency：连库只读核验线上索引是否存在且列与契约一致，
//     0=全键存在、2=缺键/列不符（阻断）、1=环境错（CI 无 config/DB → WARN，
//     该场景由上面的 check-unique-index-ddl 静态门禁兜底，不再是唯一防线）；
//   · check-runtime：只读诊断，1=WARN / 2=ERROR 多为运行环境配置（OPcache/cron_secret），
//     不是代码缺陷 → 仅记录，不阻断；
//   · check-theme-sync.js：版本戳假阳性较多（140+ 处），暂按 WARN 接入，不阻断。
section('6.7 静态审计与巡检');

foreach ([
    '后台 JS 静态审计（audit-admin-js.php）'   => 'audit-admin-js.php',
    '鉴权守卫审计（audit-auth-guards.php）'    => 'audit-auth-guards.php',
    // 第三批 B 项：业务代码禁止裸 error_log，必须走 Logger（硬阻断，非 WARN）
    '裸 error_log 审计（check-log-usage.php）' => 'check-log-usage.php',
    // 内联 SQL 收敛：已收敛控制器新增 Database:: 调用即视为回归，阻断
    '控制器内联 SQL 门禁（check-inline-sql.php）' => 'check-inline-sql.php',
    // 幂等唯一键 DDL 静态门禁：无需 config.php / MySQL，CI 恒可达，阻断契约与迁移 DDL 漂移
    '幂等唯一键 DDL 静态门禁（check-unique-index-ddl.php）' => 'check-unique-index-ddl.php',
] as $gateName => $gateScript) {
    $out = '';
    $code = run([$php, $root . '/scripts/' . $gateScript], $root, $out);
    report($gateName, $code === 0, $code === 0 ? tail($out, 2) : tail($out, 25));
}

// 幂等键核验（运行时辅助）：需要 config.php + MySQL。CI 无业务库时退出 1 → 降级 WARN；
// 真正「契约登记的键在本地库缺失或列不符」（退出 2）则阻断，防止重复支付/重复入账类回归。
// CI 可达性由上方 check-unique-index-ddl.php 静态门禁保证，此处 WARN 不再是唯一防线。
$out = '';
$code = run([$php, $root . '/scripts/check-idempotency.php'], $root, $out);
if ($code === 0) {
    echo "[PASS] 幂等唯一键核验（check-idempotency.php） — 契约登记键全部存在\n";
} elseif ($code === 2) {
    report('幂等唯一键核验（check-idempotency.php）', false, tail($out, 25));
} else {
    $warnings[] = '幂等键核验 — 环境不可用（config.php 缺失或连不上库），已跳过';
    echo "[WARN] 幂等键核验 — 环境不可用（退出码 {$code}），跳过\n";
}

// 运行时体检：只读诊断，ERROR/WARN 多为部署环境配置，与代码正确性无关，不阻断门禁。
$out = '';
$code = run([$php, $root . '/scripts/check-runtime.php'], $root, $out);
if ($code === 0) {
    echo "[PASS] 运行时体检（check-runtime.php） — 关键项 OK\n";
} else {
    $warnings[] = '运行时体检（check-runtime.php）退出码 ' . $code . '，详见脚本报告';
    echo "[WARN] 运行时体检 — 退出码 {$code}（诊断项，不阻断）\n";
}

// 主题同步：共享模块真源一致性、版本戳一致性/内容匹配。
// 当前版本戳口径（语义日期戳 vs 内容 sha1）假阳性较多，先按 WARN 接入观察。
if (!$nodeOk) {
    $warnings[] = 'check-theme-sync.js — node 不可用，跳过';
    echo "[WARN] 主题同步检查 — node 不可用，跳过\n";
} else {
    $out = '';
    $code = run(['node', $root . '/scripts/check-theme-sync.js'], $root, $out);
    if ($code === 0) {
        echo "[PASS] 主题同步检查（check-theme-sync.js） — 三类检查全通过\n";
    } else {
        $issueCount = preg_match_all('/^\s{2}\S+:\d+ \| /m', $out);
        $warnings[] = 'check-theme-sync.js 报告 ' . (int)$issueCount . ' 处问题（假阳性较多，暂不阻断）';
        echo "[WARN] 主题同步检查 — 报告 " . (int)$issueCount . " 处问题（不阻断）\n";
    }
}

// ── 6.8 路由 / OpenAPI 对账门禁（G5-1 / G5-2 / G5-3） ────────────────────
// 新增路由必须同时进 openapi.yaml；历史 403/340 差异由 docs/openapi_route_baseline.json 冻结，
// 之后差异数只减不增。两个脚本均为零外部依赖，CI 恒可达。
section('6.8 路由 / OpenAPI 对账');
$out = '';
$code = run([$php, $root . '/scripts/parse_routes.php', '--json'], $root, $out);
$parsedRoutes = $code === 0 ? json_decode($out, true) : null;
$routeCount = is_array($parsedRoutes) ? count($parsedRoutes) : 0;
report(
    '路由解析（parse_routes.php）',
    $code === 0 && $routeCount >= 400,
    $code === 0 ? "解析出 {$routeCount} 条路由" : tail($out, 10)
);

$out = '';
$code = run([$php, $root . '/scripts/check_route_docs.php'], $root, $out);
report(
    '路由/OpenAPI 差异基线（check_route_docs.php）',
    $code === 0,
    $code === 0 ? tail($out, 3) : tail($out, 25)
);

// ── 汇总 ─────────────────────────────────────────────────────────────────
echo "\n================ 门禁汇总 ================\n";
foreach ($warnings as $w) {
    echo "WARN  {$w}\n";
}
if ($failures === []) {
    echo "全部通过：语法 / 敏感文件 / 护栏 / PHPUnit(behavior+unit) / 依赖一致性 / 路由文档对账。\n";
    exit(0);
}
echo "失败 " . count($failures) . " 项：" . implode(', ', $failures) . "\n";
exit(1);
