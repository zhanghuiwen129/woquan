<?php
/**
 * 路由 / OpenAPI 对账门禁（G5-2 / G5-3，零外部依赖）
 *
 *   php scripts/check_route_docs.php                       # 用默认基线对账，退出码 0/1/2
 *   php scripts/check_route_docs.php --json                # 机器可读报告
 *   php scripts/check_route_docs.php --write-baseline      # 用真实差异重新固化基线
 *   php scripts/check_route_docs.php --routes=... --openapi=... --baseline=...
 *
 * 规则（与架构 §7 T01 / PRD G5-3 一致）：
 *   1. missing - allowed_missing 非空 → 退出 1（新增路由未进文档，护栏拦截）；
 *   2. 当前缺失数 > 基线 baseline_count → 退出 1（差异只减不增）；
 *   3. 缺失定位到具体 METHOD /path，含「路径不存在」与「路径存在但方法未文档化」两种。
 * 退出码：0 = 通过，1 = 门禁失败，2 = 输入文件缺失 / 解析失败（环境问题）。
 *
 * openapi.yaml 用逐行正则取 paths 段的顶层 path 与其下 get/post/... 方法，
 * 不引入 YAML 解析器；路由清单直接复用 scripts/parse_routes.php 的 RouteParser。
 */

declare(strict_types=1);

namespace Hublog\Docs;

require_once __DIR__ . '/parse_routes.php';

final class RouteDocChecker
{
    /** OpenAPI 里被识别为操作的方法 */
    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options'];

    /**
     * 从 openapi.yaml 的 paths 段提取路径 → 已文档化方法列表。
     *
     * @return array<string,list<string>> path => 小写方法名列表
     */
    public static function openApiMethods(string $yaml): array
    {
        $result = [];
        $inPaths = false;
        $current = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^paths:[ \t]*$/', $line)) {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // 顶层下一个键（components / tags / ...）出现即离开 paths 段
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*:[ \t]*$/', $line)) {
                break;
            }
            if (preg_match('/^  (\/[^ \t]*):[ \t]*$/', $line, $m)) {
                $current = $m[1];
                $result[$current] = [];
                continue;
            }
            if ($current !== null
                && preg_match('/^    (get|post|put|delete|patch|head|options):[ \t]*$/i', $line, $m)
            ) {
                $method = strtolower($m[1]);
                if (!in_array($method, $result[$current], true)) {
                    $result[$current][] = $method;
                }
            }
        }
        return $result;
    }

    /**
     * 只依赖路由文件与 openapi.yaml 的差异快照（生成基线时用，不需要基线已存在）。
     *
     * @return array{
     *   route_count:int,
     *   openapi_path_count:int,
     *   missing:list<string>,
     *   extra_paths:list<string>
     * }
     */
    public static function snapshot(string $routesPath, string $openapiPath): array
    {
        $routes = RouteParser::parseFile($routesPath);
        $yaml = @file_get_contents($openapiPath);
        if ($yaml === false) {
            throw new \RuntimeException("OpenAPI 文件读取失败: {$openapiPath}");
        }
        $documented = self::openApiMethods($yaml);

        $missing = [];
        foreach ($routes as $route) {
            $path = (string)$route['path'];
            $method = strtolower((string)$route['method']);
            if (!isset($documented[$path]) || !in_array($method, $documented[$path], true)) {
                $missing[] = strtoupper($method) . ' ' . $path;
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing, SORT_STRING);

        $documentedPaths = array_keys($documented);
        $routePaths = [];
        foreach ($routes as $route) {
            $routePaths[(string)$route['path']] = true;
        }
        $extraPaths = array_values(array_diff($documentedPaths, array_keys($routePaths)));
        sort($extraPaths, SORT_STRING);

        return [
            'route_count' => count($routes),
            'openapi_path_count' => count($documented),
            'missing' => $missing,
            'extra_paths' => $extraPaths,
        ];
    }

    /**
     * 对账并给出退出码（CLI 与单测共用同一份判定）。
     *
     * @return array{
     *   route_count:int,
     *   openapi_path_count:int,
     *   baseline_count:int,
     *   current_missing_count:int,
     *   missing:list<string>,
     *   allowed_missing:list<string>,
     *   unexpected:list<string>,
     *   extra_paths:list<string>,
     *   reasons:list<string>,
     *   exit_code:int
     * }
     */
    public static function evaluate(string $routesPath, string $openapiPath, string $baselinePath): array
    {
        $snapshot = self::snapshot($routesPath, $openapiPath);
        $raw = @file_get_contents($baselinePath);
        if ($raw === false) {
            throw new \RuntimeException("基线文件读取失败: {$baselinePath}（可用 --write-baseline 生成）");
        }
        $baseline = json_decode($raw, true);
        if (!is_array($baseline) || !is_array($baseline['allowed_missing'] ?? null)) {
            throw new \RuntimeException("基线文件不是合法 JSON 或缺少 allowed_missing: {$baselinePath}");
        }

        $allowed = [];
        foreach ($baseline['allowed_missing'] as $item) {
            $allowed[] = trim((string)$item);
        }
        $allowed = array_values(array_unique($allowed));
        // 基线缺 baseline_count 时按 0 处理（fail closed），防止绕过「只减不增」。
        $recordedCount = max(0, (int)($baseline['baseline_count'] ?? 0));
        $unexpected = array_values(array_diff($snapshot['missing'], $allowed));

        $reasons = [];
        if ($unexpected !== []) {
            $reasons[] = '基线外新增缺失 ' . count($unexpected) . ' 条';
        }
        if (count($snapshot['missing']) > $recordedCount) {
            $reasons[] = '差异数 ' . count($snapshot['missing']) . ' 超过基线记录 ' . $recordedCount . '（只减不增）';
        }

        return [
            'route_count' => $snapshot['route_count'],
            'openapi_path_count' => $snapshot['openapi_path_count'],
            'baseline_count' => $recordedCount,
            'current_missing_count' => count($snapshot['missing']),
            'missing' => $snapshot['missing'],
            'allowed_missing' => $allowed,
            'unexpected' => $unexpected,
            'extra_paths' => $snapshot['extra_paths'],
            'reasons' => $reasons,
            'exit_code' => $reasons === [] ? 0 : 1,
        ];
    }

    /**
     * 用真实差异固化基线（一次性生成 + 人工评审）。
     *
     * @param array{route_count:int,openapi_path_count:int,missing:list<string>,extra_paths:list<string>} $snapshot
     */
    public static function writeBaseline(string $path, array $snapshot): void
    {
        $payload = [
            'generated_at' => date('c'),
            'note' => 'G5 历史差异基线：门禁只拦 allowed_missing 之外的新增缺失，差异数只减不增。'
                . '存量补齐后请重新执行 php scripts/check_route_docs.php --write-baseline 并人工评审。',
            'baseline_count' => count($snapshot['missing']),
            'route_count' => $snapshot['route_count'],
            'openapi_path_count' => $snapshot['openapi_path_count'],
            'allowed_missing' => $snapshot['missing'],
        ];
        $json = (string)json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("基线写入失败: {$path}");
        }
    }
}

/**
 * CLI 入口。
 *
 * @param list<string> $argv
 */
function checkRouteDocsMain(array $argv): int
{
    $root = dirname(__DIR__);
    $routesPath = $root . '/api/routes.php';
    $openapiPath = $root . '/docs/openapi.yaml';
    $baselinePath = $root . '/docs/openapi_route_baseline.json';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--routes=')) {
            $routesPath = substr($arg, strlen('--routes='));
        } elseif (str_starts_with($arg, '--openapi=')) {
            $openapiPath = substr($arg, strlen('--openapi='));
        } elseif (str_starts_with($arg, '--baseline=')) {
            $baselinePath = substr($arg, strlen('--baseline='));
        }
    }
    if (in_array('--help', $argv, true)) {
        echo "用法: php scripts/check_route_docs.php [--json] [--write-baseline] "
            . "[--routes=path] [--openapi=path] [--baseline=path]\n";
        return 0;
    }

    try {
        if (in_array('--write-baseline', $argv, true)) {
            $snapshot = RouteDocChecker::snapshot($routesPath, $openapiPath);
            RouteDocChecker::writeBaseline($baselinePath, $snapshot);
            echo '[route-docs] 基线已写入 ' . $baselinePath . '：'
                . $snapshot['route_count'] . ' 条路由 / ' . $snapshot['openapi_path_count']
                . ' 个 path / ' . count($snapshot['missing']) . " 条允许缺失\n";
            return 0;
        }

        $result = RouteDocChecker::evaluate($routesPath, $openapiPath, $baselinePath);
        if (in_array('--json', $argv, true)) {
            echo (string)json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ), PHP_EOL;
            return $result['exit_code'];
        }

        echo '[route-docs] 路由 ' . $result['route_count'] . ' 条 / OpenAPI path '
            . $result['openapi_path_count'] . " 条\n";
        echo '[route-docs] 缺失文档 ' . $result['current_missing_count'] . ' 条 / 基线允许 '
            . $result['baseline_count'] . " 条\n";
        if ($result['unexpected'] !== []) {
            echo '[route-docs] [FAIL] 基线外新增缺失（METHOD /path）：' . "\n";
            foreach ($result['unexpected'] as $item) {
                echo '    ' . $item . "\n";
            }
        }
        foreach ($result['reasons'] as $reason) {
            if (!str_starts_with($reason, '基线外新增缺失')) {
                echo '[route-docs] [FAIL] ' . $reason . "\n";
            }
        }
        if ($result['exit_code'] === 0) {
            echo "[route-docs] 通过：差异已被基线冻结，未扩大\n";
        } else {
            echo '[route-docs] 失败：' . implode('；', $result['reasons']) . "\n";
        }
        return $result['exit_code'];
    } catch (\Throwable $e) {
        fwrite(STDERR, '[route-docs] ' . $e->getMessage() . "\n");
        return 2;
    }
}

// 被 require 时只提供类，不产生任何输出；直接运行时才走 CLI。
if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(checkRouteDocsMain($argv));
}
