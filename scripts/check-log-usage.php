<?php
/**
 * 裸 error_log() 审计（第三批改造 B 项：日志双轨统一）
 *
 * 治理目标：业务代码必须统一走 Hublog\Core\Logger（info/warning/error，
 * 写 logs/hublog-YYYY-MM-DD.log 结构化落盘 + error 级额外 error_log 一份），
 * 禁止在业务路径里直接写裸 error_log()，否则日志格式/脱敏/轮转全部绕开。
 *
 * 扫描范围：api/controllers/、api/core/、plugins/ 下的所有 .php 源码。
 *
 * 白名单（精确匹配，避免误伤）：
 *   1. 迁移/升级基础设施：文件名以 Migration 开头，或 Upgrade.php
 *      （CLI 迁移脚本的 error_log 是进度输出，属合理用途，必须保留）；
 *   2. api/core/Cache.php（Redis 驱动降级日志）；
 *   3. api/core/Logger.php 自身（write() 里 error 级刻意保留一份 error_log）；
 *   4. 单行带显式豁免注释 `// @allow-error-log` 的调用
 *      （用于确需保留裸调用的例外，每一处都可在 --list 中审查）。
 *
 * 用法：
 *   php scripts/check-log-usage.php          # CI 门禁：有违规 exit 1，干净 exit 0
 *   php scripts/check-log-usage.php --list   # 审查模式：列出全部命中及白名单标注，恒 exit 0
 *
 * 退出码：
 *   0 = 干净（默认模式无违规；--list 恒为 0）
 *   1 = 存在未豁免的裸 error_log()
 */

declare(strict_types=1);

$root = str_replace('\\', '/', dirname(__DIR__));
$listMode = in_array('--list', $argv, true);

/**
 * 判断文件是否整体属于白名单（与具体行无关）。
 *
 * 用「相对根路径」而非 basename 做精确判断，防止 plugins/ 下同名文件被误伤。
 */
function whitelistedFile(string $rel): ?string
{
    $base = basename($rel);
    // 迁移/升级基础设施：Migration.php / MigrationBatch*.php / MigrationHistory.php / Upgrade.php
    // 注意不能用宽泛的 M* —— Mailer.php / Moderation.php / Upload.php 都是业务代码。
    if (str_starts_with($base, 'Migration') || $base === 'Upgrade.php') {
        return '迁移/升级基础设施';
    }
    if ($rel === 'api/core/Cache.php') {
        return 'Redis 降级日志';
    }
    if ($rel === 'api/core/Logger.php') {
        return 'Logger 自身';
    }
    return null;
}

/**
 * 扫描目录，返回按 相对路径 => [行号 => 行文本] 组织的命中结果。
 */
function scanDirs(string $root, array $dirs): array
{
    $hits = [];
    foreach ($dirs as $dir) {
        $full = $root . '/' . $dir;
        if (!is_dir($full)) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $rel = str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $i => $line) {
                // 裸调用：前面不能是 -> / :: / 标识符字符（排除 $this->error_log() 之类）
                // 注意 \error_log( 这类显式全局调用仍属裸调用，应当命中
                if (!preg_match('/(?<![>:\\w])error_log\s*\(/', $line)) {
                    continue;
                }
                $trimmed = ltrim($line);
                // 纯注释行（// … / # … / * … / /* …）里的字面量不算调用
                if ($trimmed === '' || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                $hits[$rel][$i + 1] = rtrim($line);
            }
        }
    }
    ksort($hits, SORT_STRING);
    return $hits;
}

/**
 * 自测：在临时目录构造 fixture，验证白名单 / 豁免 / 违规 / 注释过滤逻辑。
 * 用法：php scripts/check-log-usage.php --self-test
 */
function runSelfTest(): int
{
    $tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/hublog_log_audit_' . bin2hex(random_bytes(6));
    $fixtures = [
        // 相对路径 => [期望归类, 源码]
        'api/core/MigrationFake.php'  => ['whitelist', "<?php\nerror_log('migration');\n"],
        'api/core/Upgrade.php'        => ['whitelist', "<?php\nerror_log('upgrade');\n"],
        'api/core/Cache.php'          => ['whitelist', "<?php\nerror_log('cache');\n"],
        'api/core/Logger.php'         => ['whitelist', "<?php\nerror_log('logger');\n"],
        'api/controllers/Bad.php'     => ['violation', "<?php\nerror_log('bad');\n"],
        'api/controllers/Allowed.php' => ['exempt', "<?php\nerror_log('allowed'); // @allow-error-log\n"],
        'api/controllers/Comment.php' => ['none', "<?php\n// error_log('in comment');\n"],
        'api/controllers/Method.php'  => ['none', "<?php\n\$this->error_log('method');\n"],
        'plugins/Demo/Plugin.php'     => ['violation', "<?php\nerror_log('plugin');\n"],
    ];
    foreach (array_keys($fixtures) as $rel) {
        $p = $tmp . '/' . $rel;
        @mkdir(dirname($p), 0777, true);
        file_put_contents($p, $fixtures[$rel][1]);
    }

    $hits = scanDirs($tmp, ['api/controllers', 'api/core', 'plugins']);
    $actual = [];
    foreach ($hits as $rel => $lines) {
        foreach ($lines as $lineNo => $text) {
            $actual[$rel] = $lineNo;
        }
    }

    $fail = 0;
    foreach ($fixtures as $rel => [$expect, $_src]) {
        $present = isset($actual[$rel]);
        if ($expect === 'none') {
            $ok = !$present;
        } else {
            $ok = $present;
            if ($present) {
                $text = '';
                foreach ($hits[$rel] as $lineText) {
                    $text = $lineText;
                }
                $classified = whitelistedFile($rel) !== null ? 'whitelist'
                    : (str_contains($text, '@allow-error-log') ? 'exempt' : 'violation');
                $ok = $classified === $expect;
            }
        }
        printf("[self-test] %-32s %s\n", $rel, $ok ? 'OK' : 'FAIL');
        if (!$ok) {
            $fail++;
        }
    }

    // 清理临时目录
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);

    if ($fail > 0) {
        echo "[self-test] ✗ {$fail} 项失败\n";
        return 1;
    }
    echo "[self-test] ✓ 全部 " . count($fixtures) . " 项通过\n";
    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$hits = scanDirs($root, ['api/controllers', 'api/core', 'plugins']);

$violations = 0;
$whitelistedHits = 0;
$exemptHits = 0;
$totalHits = 0;

echo '[log] ' . date('c') . " 裸 error_log() 审计" . ($listMode ? '（--list 审查模式）' : '') . "\n";

foreach ($hits as $rel => $lines) {
    $fileRule = whitelistedFile($rel);
    ksort($lines, SORT_NUMERIC);
    foreach ($lines as $lineNo => $text) {
        $totalHits++;
        // --list 打印消息正文（截断），便于逐条审查豁免是否合理
        $preview = trim($text);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($preview) > 120) {
                $preview = mb_substr($preview, 0, 120) . '…';
            }
        } elseif (strlen($preview) > 240) {
            $preview = substr($preview, 0, 240) . '…';
        }
        if ($fileRule !== null) {
            $whitelistedHits++;
            if ($listMode) {
                printf("[W:%s] %s:%d  %s\n", $fileRule, $rel, $lineNo, $preview);
            }
            continue;
        }
        if (str_contains($text, '@allow-error-log')) {
            $exemptHits++;
            if ($listMode) {
                printf("[W:显式豁免] %s:%d  %s\n", $rel, $lineNo, $preview);
            }
            continue;
        }
        $violations++;
        if ($listMode) {
            printf("[违规] %s:%d  %s\n", $rel, $lineNo, $preview);
        } else {
            printf("[log] 违规: %s:%d  %s\n", $rel, $lineNo, $preview);
        }
    }
}

echo sprintf(
    "[log] 命中 %d 处：白名单文件 %d、显式豁免 %d、违规 %d\n",
    $totalHits,
    $whitelistedHits,
    $exemptHits,
    $violations
);

if ($listMode) {
    echo "[log] --list 仅审查，不影响退出码。\n";
    exit(0);
}

if ($violations > 0) {
    fwrite(STDERR, "[log] ✗ 存在 {$violations} 处未豁免的裸 error_log()，请改用 Logger::error/warning 或加 // @allow-error-log 说明理由。\n");
    exit(1);
}

echo "[log] ✓ 审计通过\n";
exit(0);
