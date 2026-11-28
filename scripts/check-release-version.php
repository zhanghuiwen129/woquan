<?php
/**
 * 发布版本三值绑定校验（纯只读：不写文件、不改 config.php、不创建任何 git 对象）。
 *
 * 三个值属于两个不同维度，不要求数值相等：
 *   1. git tag                       —— 当前 HEAD 指向的 tag（发布纪律要求必须有）
 *   2. APP_VERSION                   —— config.php 中 define('APP_VERSION', '...')
 *   3. Migration::$currentVersion    —— 迁移 schema 版本，独立校验为合法正整数
 *
 * 绑定规则：只要求去 v/V 前缀后的 tag 与 APP_VERSION 字符串一致；
 * Migration 版本单独打印，仅校验它是正整数。
 *
 * 环境性事实（必须优雅降级）：
 *   - 当前仓库无 tag、CI 源码包检出无 .git、CI 无 config.php（config.php 是安装程序生成、
 *     明确禁止入库的文件）—— 这些都是 WARN，退出码仍为 0，绝不阻断流水线。
 *   - 只有 tag 与 APP_VERSION 真不一致、或 Migration schema 版本非法才 FAIL（退出码 1）。
 *
 * 用法：
 *   php scripts/check-release-version.php
 *   php scripts/check-release-version.php --json
 *   php scripts/check-release-version.php --tag=v2.16.29 --version=v2.16.29 --migration=101
 *   php scripts/check-release-version.php --tag= --version=v2.16.29          # 模拟无 tag
 *   php scripts/check-release-version.php --root=/tmp/probe --config=... --migration-file=...
 *
 * 退出码：0 = 通过或环境性 WARN；1 = 绑定不一致 / schema 版本非法。
 * 注意 WARN 也返回 0，CI 必须通过 --json 的 status 字段（pass / warn / fail）区分。
 */
declare(strict_types=1);

/**
 * 捕获子进程输出（数组 argv 不经 shell；proc_open 被禁时回退 exec + escapeshellarg）。
 *
 * @param list<string> $cmd
 * @return array{code:int, out:string}
 */
function runCapture(array $cmd, string $cwd): array
{
    if (function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = (string)stream_get_contents($pipes[1]);
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            return ['code' => $code, 'out' => trim($stdout . "\n" . $stderr)];
        }
    }

    if (!function_exists('exec')) {
        return ['code' => 127, 'out' => 'proc_open 与 exec 均不可用，无法执行 git'];
    }

    $line = implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1';
    $oldCwd = getcwd();
    if (is_dir($cwd)) {
        @chdir($cwd);
    }
    $lines = [];
    $code = 0;
    @exec($line, $lines, $code);
    if ($oldCwd !== false) {
        @chdir($oldCwd);
    }
    return ['code' => $code, 'out' => trim(implode("\n", $lines))];
}

/** 取多行输出的首行摘要，避免把 git 的整段 fatal 日志塞进报告。 */
function firstLine(string $out): string
{
    $lines = array_values(array_filter(
        preg_split('/\R/', trim($out)) ?: [],
        static fn(string $l): bool => trim($l) !== ''
    ));
    return trim($lines[0] ?? '');
}

/**
 * 读取 HEAD 上的 tag。$override 非 null 时为探针覆盖；'' 表示强制「无 tag」。
 *
 * @return array{tags:list<string>, error:?string}
 */
function readGitTags(string $root, ?string $override): array
{
    if ($override !== null) {
        return ['tags' => $override === '' ? [] : [$override], 'error' => null];
    }

    $probe = runCapture(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree'], $root);
    if ($probe['code'] !== 0 || strtolower(trim($probe['out'])) !== 'true') {
        return [
            'tags' => [],
            'error' => 'git 不可用或不是 git 工作树' . (firstLine($probe['out']) !== '' ? '：' . firstLine($probe['out']) : ''),
        ];
    }

    // 优先取精确指向 HEAD 的全部 tag；旧版 git 无 --points-at 时回退 describe。
    $result = runCapture(['git', '-C', $root, 'tag', '--points-at', 'HEAD'], $root);
    if ($result['code'] !== 0) {
        $result = runCapture(['git', '-C', $root, 'describe', '--tags', '--exact-match', 'HEAD'], $root);
    }
    if ($result['code'] !== 0) {
        return [
            'tags' => [],
            'error' => 'git tag 查询失败' . (firstLine($result['out']) !== '' ? '：' . firstLine($result['out']) : ''),
        ];
    }

    $tags = array_values(array_filter(
        array_map('trim', preg_split('/\R/', trim($result['out'])) ?: []),
        static fn(string $t): bool => $t !== ''
    ));
    sort($tags, SORT_STRING);
    return ['tags' => $tags, 'error' => null];
}

/**
 * 只读解析 config.php 的 APP_VERSION（沿用 Upgrade::syncLocalVersion 的正则口径）。
 *
 * @return array{value:?string, error:?string}
 */
function readAppVersion(string $path, ?string $override): array
{
    if ($override !== null) {
        $value = trim($override);
        return $value === ''
            ? ['value' => null, 'error' => '--version 覆盖值为空']
            : ['value' => $value, 'error' => null];
    }

    if (!is_file($path) || !is_readable($path)) {
        return ['value' => null, 'error' => 'config.php 不可读：' . $path];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return ['value' => null, 'error' => 'config.php 读取失败：' . $path];
    }

    $pattern = "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)\s*;/";
    foreach ((preg_split('/\R/', $content) ?: []) as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            continue;
        }
        if (preg_match($pattern, $line, $matches) === 1) {
            $value = trim($matches[1]);
            return $value === ''
                ? ['value' => null, 'error' => 'config.php 中 APP_VERSION 为空']
                : ['value' => $value, 'error' => null];
        }
    }
    return ['value' => null, 'error' => 'config.php 中未解析到 APP_VERSION 定义'];
}

/**
 * 只读解析 Migration::$currentVersion，并校验为合法正整数。
 *
 * @return array{value:?int, error:?string}
 */
function readMigrationVersion(string $path, ?string $override): array
{
    if ($override !== null) {
        $value = trim($override);
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return ['value' => null, 'error' => '--migration 不是合法正整数：' . $override];
        }
        return ['value' => (int)$value, 'error' => null];
    }

    if (!is_file($path) || !is_readable($path)) {
        return ['value' => null, 'error' => 'Migration.php 不可读：' . $path];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return ['value' => null, 'error' => 'Migration.php 读取失败：' . $path];
    }

    $pattern = '/private\s+static\s+int\s+\$currentVersion\s*=\s*([0-9]+)\s*;/';
    foreach ((preg_split('/\R/', $content) ?: []) as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            continue;
        }
        if (preg_match($pattern, $line, $matches) === 1) {
            $number = (int)$matches[1];
            return $number > 0
                ? ['value' => $number, 'error' => null]
                : ['value' => null, 'error' => 'Migration::$currentVersion 不是正整数：' . $matches[1]];
        }
    }
    return ['value' => null, 'error' => 'Migration.php 中未解析到 $currentVersion 定义'];
}

/** 去掉单个前导 v/V 前缀后比较版本号。 */
function normalizeVersion(string $value): string
{
    return (string)preg_replace('/^[vV]/', '', trim($value));
}

/**
 * 解析命令行参数。
 *
 * @param list<string> $argv
 * @return array{json:bool, root:string, tag:?string, version:?string, migration:?string, config:?string, migrationFile:?string}
 */
function parseArgs(array $argv, string $root): array
{
    $opts = [
        'json' => false,
        'root' => $root,
        'tag' => null,
        'version' => null,
        'migration' => null,
        'config' => null,
        'migrationFile' => null,
    ];

    $prefixes = [
        '--root=' => 'root',
        '--tag=' => 'tag',
        '--version=' => 'version',
        '--migration=' => 'migration',
        '--config=' => 'config',
        '--migration-file=' => 'migrationFile',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "用法: php scripts/check-release-version.php [--json] [--tag=TAG] "
                . "[--version=VER] [--migration=N] [--root=DIR] [--config=FILE] [--migration-file=FILE]\n");
            exit(0);
        }

        $matched = false;
        foreach ($prefixes as $prefix => $key) {
            if (str_starts_with($arg, $prefix)) {
                $opts[$key] = substr($arg, strlen($prefix));
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            fwrite(STDERR, "未知参数: {$arg}\n");
            exit(1);
        }
    }

    return $opts;
}

/**
 * 渲染人类可读报告。
 *
 * @param array<string,mixed> $result
 */
function renderHuman(array $result): string
{
    $tag = $result['tag'] ?? null;
    $tags = $result['tags'] ?? [];
    $tagText = $tag === null ? '(无)' : $tag . (count($tags) > 1 ? '（HEAD 指向多个 tag：' . implode(', ', $tags) . '）' : '');
    $lines = [
        '=== 发布版本三值绑定校验（只读） ===',
        'git tag        : ' . $tagText,
        'APP_VERSION    : ' . ($result['appVersion'] ?? '(不可读)'),
        'Migration 版本 : ' . (($result['migrationVersion'] ?? null) ?? '(不可解析)')
            . '  [schema 维度，独立校验；不要求与 APP_VERSION 数值相等]',
        '',
    ];

    /** @var list<array{severity:string, code:string, message:string}> $issues */
    $issues = $result['issues'] ?? [];
    foreach ($issues as $issue) {
        // 硬错误统一显示为 [FAIL]，与 ci.php 的 [FAIL]/[WARN] 报告口径一致。
        $label = $issue['severity'] === 'error' ? 'FAIL' : 'WARN';
        $lines[] = '[' . $label . '] ' . $issue['message'];
    }

    $tagNorm = $result['normalizedTag'] ?? null;
    $appNorm = $result['normalizedAppVersion'] ?? null;
    if ($issues === []) {
        $lines[] = '[PASS] git tag 与 APP_VERSION 绑定一致（' . $tagNorm
            . '）；Migration schema 版本 ' . $result['migrationVersion'] . ' 合法';
    }

    $status = (string)($result['status'] ?? 'fail');
    if ($status === 'fail') {
        $lines[] = '[FAIL] 结论：三值绑定校验失败，发布必须中止';
    } elseif ($status === 'warn') {
        $lines[] = '[WARN] 结论：跳过三值绑定校验（' . count($issues)
            . ' 项环境性警告），退出码 0；正式发布前须补齐对应项';
    } else {
        $lines[] = '[PASS] 结论：三值绑定校验通过';
    }

    return implode("\n", $lines) . "\n";
}

// ── 主流程 ────────────────────────────────────────────────────────────────
$defaultRoot = dirname(__DIR__);
$opts = parseArgs($argv, $defaultRoot);
$root = rtrim($opts['root'], '/\\');
if ($root === '') {
    $root = $defaultRoot;
}
if (!is_dir($root)) {
    fwrite(STDERR, "指定的根目录不存在: {$root}\n");
    exit(1);
}

$configPath = $opts['config'] ?? ($root . '/config.php');
$migrationPath = $opts['migrationFile'] ?? ($root . '/api/core/Migration.php');

$gitInfo = readGitTags($root, $opts['tag']);
$appInfo = readAppVersion($configPath, $opts['version']);
$migrationInfo = readMigrationVersion($migrationPath, $opts['migration']);

/** @var list<array{severity:string, code:string, message:string}> $issues */
$issues = [];
$addIssue = static function (string $severity, string $code, string $message) use (&$issues): void {
    $issues[] = ['severity' => $severity, 'code' => $code, 'message' => $message];
};

if ($gitInfo['error'] !== null) {
    $addIssue('warn', 'GIT_UNAVAILABLE', $gitInfo['error'] . ' — 跳过 tag 绑定校验（发布纪律：无 tag 不得发布）');
} elseif ($gitInfo['tags'] === []) {
    $addIssue('warn', 'NO_GIT_TAG', '当前 HEAD 无 tag — 跳过三值绑定校验（发布前请打 tag）');
} elseif (count($gitInfo['tags']) > 1) {
    $addIssue('warn', 'MULTIPLE_TAGS', 'HEAD 指向多个 tag：' . implode(', ', $gitInfo['tags']) . ' — 取任一匹配即视为通过');
}

if ($appInfo['error'] !== null) {
    // config.php 是安装程序生成、明确禁止入库的文件（ci.php 第 2 节会检查它未被跟踪），
    // CI / 源码包检出场景常无此文件，因此按环境性 WARN 处理而非 FAIL。
    $addIssue('warn', 'APP_VERSION_UNREADABLE', $appInfo['error'] . ' — 跳过 tag/APP_VERSION 绑定校验（发布机上必须存在）');
}

if ($migrationInfo['error'] !== null) {
    // Migration.php 是受版本控制的源码文件，CI 恒可达；解析失败是真实代码缺陷，阻断。
    $addIssue('error', 'MIGRATION_INVALID', $migrationInfo['error'] . ' — Migration schema 版本必须为正整数');
}

$normalizedTag = null;
$normalizedApp = ($appInfo['value'] !== null) ? normalizeVersion($appInfo['value']) : null;
$tag = $gitInfo['tags'][0] ?? null;
if ($tag !== null) {
    $normalizedTag = normalizeVersion($tag);
}
if ($tag !== null && $appInfo['value'] !== null) {
    $matched = false;
    foreach ($gitInfo['tags'] as $candidate) {
        if (normalizeVersion($candidate) === $normalizedApp) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $tagLabel = count($gitInfo['tags']) > 1
            ? 'git tag 列表 [' . implode(', ', $gitInfo['tags']) . ']'
            : 'git tag ' . $tag;
        $addIssue(
            'error',
            'VERSION_MISMATCH',
            $tagLabel . ' 与 APP_VERSION ' . $appInfo['value'] . ' 不一致（已去除 v/V 前缀后比较）'
        );
    }
}

$hasError = false;
foreach ($issues as $issue) {
    if ($issue['severity'] === 'error') {
        $hasError = true;
        break;
    }
}
$status = $hasError ? 'fail' : ($issues !== [] ? 'warn' : 'pass');
$exitCode = $hasError ? 1 : 0;

$summary = $status === 'fail'
    ? '三值绑定校验失败：' . implode('；', array_column(array_filter(
        $issues,
        static fn(array $i): bool => $i['severity'] === 'error'
    ), 'message'))
    : ($status === 'warn'
        ? implode('；', array_column($issues, 'message'))
        : 'git tag 与 APP_VERSION 绑定一致，Migration schema 版本合法');

$result = [
    'status' => $status,
    'exitCode' => $exitCode,
    'tag' => $tag,
    'tags' => $gitInfo['tags'],
    'appVersion' => $appInfo['value'],
    'migrationVersion' => $migrationInfo['value'],
    'normalizedTag' => $normalizedTag,
    'normalizedAppVersion' => $normalizedApp,
    'issues' => $issues,
    'message' => $summary,
    'sources' => [
        'root' => $root,
        'config' => $configPath,
        'migrationFile' => $migrationPath,
        'tagOverride' => $opts['tag'],
    ],
];

if ($opts['json']) {
    echo (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} else {
    echo renderHuman($result);
}
exit($exitCode);
