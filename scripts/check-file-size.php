<?php
/**
 * 单文件行数只减不增门禁（P1-2，零外部依赖）
 *
 *   php scripts/check-file-size.php                    # 用冻结基线对账，退出码 0/1/2
 *   php scripts/check-file-size.php --json             # 机器可读报告
 *   php scripts/check-file-size.php --write-baseline   # 用当前源码重新固化基线（须人工评审）
 *   php scripts/check-file-size.php --root=... --dirs=api/core,api/controllers --baseline=...
 *
 * 阈值：300 行。受管目录：api/core、api/controllers、api/middleware 下的 .php，
 * 跳过 tests / vendor / node_modules / dist / cache / logs / tmp / backups / fixtures。
 * 豁免清单（仅生成物）：api/core/FreshInstallSchema.php、api/core/MigrationContract.php；
 * 严禁把 License.php / MigrationBatchC.php 加入豁免——它们正是本门禁要冻结的对象。
 *
 * 判定：
 *   - 基线已有记录且当前行数 > 记录行数 → GREW（FAIL）；
 *   - 无基线记录且当前行数 > 300 且非豁免 → NEW_OVER_THRESHOLD（FAIL，防膨胀迁移到新文件）；
 *   - 缩小、删除文件不 FAIL；新增但不超过阈值的文件不 FAIL。
 * 行数口径：先把 \r\n / \r 归一为 \n 再 count，基线只存整数不存哈希（兼容 core.autocrlf）。
 *
 * 退出码：0 = 通过，1 = 行数违规，2 = 环境/输入错误（基线缺失、目录不存在、JSON 损坏）。
 *
 * 降摩擦提示：若团队希望先观察一个迭代，只需把 scripts/ci.php 的 6.10 段
 * report(...) 第 4 参数传 $warn（例如 '基线冻结中，先按 WARN 观察'），本脚本无需改动。
 */

declare(strict_types=1);

namespace Hublog\Docs;

final class FileSizeBaselineChecker
{
    /** 单文件行数阈值（逻辑行） */
    public const THRESHOLD = 300;

    /** 默认受管目录 */
    public const DEFAULT_DIRS = ['api/core', 'api/controllers', 'api/middleware'];

    /** 默认豁免清单：仅代码生成物 */
    public const DEFAULT_EXEMPT = [
        'api/core/FreshInstallSchema.php',
        'api/core/MigrationContract.php',
    ];

    /** 扫描时跳过的目录名（路径任一层命中即跳过） */
    private const EXCLUDED_DIRS = [
        'tests', 'vendor', 'node_modules', 'dist', 'cache',
        'logs', 'tmp', 'backups', 'fixtures',
    ];

    /** 基线 note 文案：提醒重冻基线需要人工评审 */
    private const BASELINE_NOTE = 'P1-2 单文件行数只减不增冻结基线：受管文件当前行数 > 基线即 FAIL；'
        . '新增文件超阈值且不在 exempt 中同样 FAIL。存量收敛后请 --write-baseline 并人工评审。';

    /** 计算逻辑行数：统一 CRLF / CR / LF，末尾空元素不计。 */
    public static function countLogicalLines(string $raw): int
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        return count($lines);
    }

    /**
     * 扫描受管目录，返回非豁免文件的逻辑行数表。
     *
     * @param list<string> $dirs
     * @param list<string> $exempt
     * @return array{files:array<string,int>,scanned:int,skipped:int,exempted:int,dirs:list<string>}
     */
    public static function scan(string $root, array $dirs, array $exempt = self::DEFAULT_EXEMPT): array
    {
        $normalizedRoot = self::normalizeRoot($root);
        $files = [];
        $scanned = 0;
        $skipped = 0;
        $exempted = 0;
        $exemptSet = array_fill_keys($exempt, true);
        $normalizedDirs = [];

        foreach ($dirs as $dir) {
            $dir = trim(str_replace('\\', '/', (string)$dir), '/');
            if ($dir === '') {
                throw new \RuntimeException('受管目录不能为空');
            }
            $normalizedDirs[] = $dir;
            $base = $normalizedRoot . '/' . $dir;
            if (!is_dir($base)) {
                throw new \RuntimeException('受管目录不存在: ' . $dir);
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') {
                    continue;
                }
                $path = self::normalizeRoot($info->getPathname());
                $relative = self::relativePath($normalizedRoot, $path);
                if (self::isExcluded($relative)) {
                    $skipped++;
                    continue;
                }
                $scanned++;
                if (isset($exemptSet[$relative])) {
                    $exempted++;
                    continue;
                }
                $raw = @file_get_contents($path);
                if ($raw === false) {
                    throw new \RuntimeException('无法读取受管文件: ' . $relative);
                }
                $files[$relative] = self::countLogicalLines($raw);
            }
        }

        ksort($files, SORT_STRING);
        return [
            'files' => $files,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'exempted' => $exempted,
            'dirs' => $normalizedDirs,
        ];
    }

    /** 由扫描结果构造基线：只冻结超过阈值的文件，其余由阈值规则兜底。 */
    public static function makeBaseline(
        array $scan,
        int $threshold = self::THRESHOLD,
        array $dirs = self::DEFAULT_DIRS,
        array $exempt = self::DEFAULT_EXEMPT
    ): array {
        $frozen = [];
        foreach ($scan['files'] as $path => $lines) {
            if ((int)$lines > $threshold) {
                $frozen[(string)$path] = (int)$lines;
            }
        }
        ksort($frozen, SORT_STRING);
        return [
            'note' => self::BASELINE_NOTE,
            'threshold' => $threshold,
            'checker_version' => 1,
            'dirs' => array_values($dirs),
            'exempt' => array_values($exempt),
            'count' => count($frozen),
            'files' => $frozen,
        ];
    }

    /** 写基线（LF 行尾，pretty JSON）。 */
    public static function writeBaseline(string $path, array $baseline): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建基线目录: ' . $dir);
        }
        $json = (string)json_encode(
            $baseline,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('无法写入基线文件: ' . $path);
        }
    }

    /**
     * 对账：基线有记录 → 只减不增；无记录 → 超阈值即 FAIL。
     *
     * @param array<string,int> $current
     * @param array<string,int> $baselineFiles
     * @return array{ok:bool,violations:list<array<string,mixed>>,grew:int,new_over:int}
     */
    public static function compare(array $current, array $baselineFiles, int $threshold = self::THRESHOLD): array
    {
        $violations = [];
        $grew = 0;
        $newOver = 0;

        foreach ($current as $path => $lines) {
            $path = (string)$path;
            $lines = (int)$lines;
            if (array_key_exists($path, $baselineFiles)) {
                $recorded = (int)$baselineFiles[$path];
                if ($lines > $recorded) {
                    $grew++;
                    $violations[] = [
                        'type' => 'GREW',
                        'path' => $path,
                        'baseline' => $recorded,
                        'current' => $lines,
                        'message' => 'GREW ' . $path . ': ' . $recorded . ' → ' . $lines,
                    ];
                }
                continue;
            }
            if ($lines > $threshold) {
                $newOver++;
                $violations[] = [
                    'type' => 'NEW_OVER_THRESHOLD',
                    'path' => $path,
                    'baseline' => null,
                    'current' => $lines,
                    'message' => 'NEW_OVER_THRESHOLD ' . $path . ': ' . $lines . ' > ' . $threshold,
                ];
            }
        }

        return [
            'ok' => $violations === [],
            'violations' => $violations,
            'grew' => $grew,
            'new_over' => $newOver,
        ];
    }

    /** 读取并校验基线文件；结构异常按环境错误处理（退出 2）。 */
    public static function loadBaseline(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('基线文件缺失: ' . $path . '（先运行 --write-baseline）');
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('基线文件无法读取: ' . $path);
        }
        $baseline = json_decode($raw, true);
        if (!is_array($baseline) || !isset($baseline['files']) || !is_array($baseline['files'])) {
            throw new \RuntimeException('基线文件不是合法 JSON 或缺少 files: ' . $path);
        }
        return $baseline;
    }

    /**
     * 完整对账扫描（供 CLI 使用）。
     *
     * @param list<string>|null $dirsOverride 显式 --dirs 时覆盖基线中的目录清单
     */
    public static function evaluate(
        string $root,
        string $baselinePath,
        int $threshold = self::THRESHOLD,
        ?array $dirsOverride = null
    ): array {
        $baseline = self::loadBaseline($baselinePath);
        $dirs = $dirsOverride ?? (isset($baseline['dirs']) && is_array($baseline['dirs']) && $baseline['dirs'] !== []
            ? array_values($baseline['dirs'])
            : self::DEFAULT_DIRS);
        $exempt = isset($baseline['exempt']) && is_array($baseline['exempt'])
            ? array_values($baseline['exempt'])
            : self::DEFAULT_EXEMPT;
        if (isset($baseline['threshold']) && (int)$baseline['threshold'] !== $threshold) {
            throw new \RuntimeException('基线阈值 ' . $baseline['threshold'] . ' 与脚本阈值 ' . $threshold . ' 不一致');
        }

        $scan = self::scan($root, $dirs, $exempt);
        $result = self::compare($scan['files'], $baseline['files'], $threshold);
        return [
            'ok' => $result['ok'],
            'violations' => $result['violations'],
            'scanned' => $scan['scanned'],
            'skipped' => $scan['skipped'],
            'exempted' => $scan['exempted'],
            'threshold' => $threshold,
            'baseline_count' => count($baseline['files']),
            'exit_code' => $result['ok'] ? 0 : 1,
        ];
    }

    /** 绝对路径统一为斜杠形式，去掉尾部斜杠。 */
    private static function normalizeRoot(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return rtrim($path, '/');
    }

    /** 取相对项目根的路径（统一斜杠）。 */
    private static function relativePath(string $root, string $path): string
    {
        $prefix = rtrim($root, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** 路径任一层目录命中排除清单即跳过（文件名本身不参与判定）。 */
    private static function isExcluded(string $relative): bool
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::EXCLUDED_DIRS, true)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * CLI 入口（被 require 时只提供类，不产生输出）。
 *
 * @param list<string> $argv
 */
function checkFileSizeMain(array $argv): int
{
    $root = dirname(__DIR__);
    $baselinePath = $root . '/docs/file_size_baseline.json';
    $dirs = FileSizeBaselineChecker::DEFAULT_DIRS;
    $dirsOverridden = false;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = substr($arg, strlen('--root='));
        } elseif (str_starts_with($arg, '--baseline=')) {
            $baselinePath = substr($arg, strlen('--baseline='));
        } elseif (str_starts_with($arg, '--dirs=')) {
            $custom = array_values(array_filter(
                array_map('trim', explode(',', substr($arg, strlen('--dirs=')))),
                static fn(string $d): bool => $d !== ''
            ));
            if ($custom === []) {
                fwrite(STDERR, "[filesize] --dirs 不能为空\n");
                return 2;
            }
            $dirs = $custom;
            $dirsOverridden = true;
        }
    }

    if (in_array('--help', $argv, true)) {
        echo "用法: php scripts/check-file-size.php [--check] [--json] [--write-baseline] "
            . "[--root=path] [--dirs=a,b] [--baseline=path]\n";
        return 0;
    }

    try {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($root)) {
            throw new \RuntimeException('项目根目录不存在: ' . $root);
        }

        if (in_array('--write-baseline', $argv, true)) {
            $scan = FileSizeBaselineChecker::scan($root, $dirs);
            $baseline = FileSizeBaselineChecker::makeBaseline($scan, FileSizeBaselineChecker::THRESHOLD, $dirs);
            FileSizeBaselineChecker::writeBaseline($baselinePath, $baseline);
            echo '[filesize] 基线已写入 ' . $baselinePath . '：扫描 ' . $scan['scanned']
                . ' 个受管文件，冻结 ' . $baseline['count'] . " 个超阈值文件（阈值 300）\n";
            return 0;
        }

        $result = FileSizeBaselineChecker::evaluate($root, $baselinePath, FileSizeBaselineChecker::THRESHOLD, $dirsOverridden ? $dirs : null);
        if (in_array('--json', $argv, true)) {
            echo (string)json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ), PHP_EOL;
            return $result['exit_code'];
        }

        echo '[filesize] 受管文件 ' . $result['scanned'] . ' / 跳过 ' . $result['skipped']
            . ' / 豁免 ' . $result['exempted'] . ' / 基线记录 ' . $result['baseline_count']
            . ' / 阈值 ' . $result['threshold'] . "\n";
        if ($result['violations'] !== []) {
            echo '[filesize] [FAIL] 单文件行数只允许只减不增：' . count($result['violations']) . " 处违规\n";
            foreach ($result['violations'] as $violation) {
                echo '    ' . $violation['message'] . "\n";
            }
            echo "[filesize] 收敛后请人工评审并执行 --write-baseline 重新冻结\n";
        } elseif ($result['exit_code'] === 0) {
            echo "[filesize] 通过：无文件行数增长，新增文件均未超阈值\n";
        }
        return $result['exit_code'];
    } catch (\Throwable $e) {
        fwrite(STDERR, '[filesize] ' . $e->getMessage() . "\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(checkFileSizeMain($argv));
}
