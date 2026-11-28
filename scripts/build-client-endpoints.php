<?php
/**
 * 客户端 API 端点清单生成器（P1-1，零外部依赖）
 *
 *   php scripts/build-client-endpoints.php            # 生成 docs/client_endpoints.json
 *   php scripts/build-client-endpoints.php --check    # 校验清单是否最新（CI 用，退出码 1 = 过期）
 *   php scripts/build-client-endpoints.php --report   # 只看统计，不写文件
 *   php scripts/build-client-endpoints.php --openapi=... --out=...
 *
 * 单一契约源：docs/openapi.yaml。新增 / 改动接口必须先改契约，再跑本生成器提交端点清单，
 * 否则 --check 与 CI 的「客户端契约对账」会直接报「清单过期」。
 *
 * 解析方式：逐行扫描 paths 段的顶层 path（两空格缩进）与其下的 HTTP 方法（四空格缩进），
 * 不引入 YAML 解析器，与 scripts/check_route_docs.php 保持同一口径，零运行时 Composer 依赖。
 * 清单不含时间戳，内容完全由 openapi.yaml 决定，因此 --check 可逐字节比对、重复运行幂等。
 */

declare(strict_types=1);

namespace Hublog\Docs;

final class ClientEndpointManifestBuilder
{
    /** OpenAPI 里被识别为操作的方法 */
    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options', 'trace'];

    /**
     * 从 openapi.yaml 的 paths 段提取端点：path => 方法集合 + 标签集合。
     *
     * @return array<string,array{methods:array<string,true>,tags:array<string,true>}>
     */
    public static function parseOpenApi(string $yaml): array
    {
        $byPath = [];
        $inPaths = false;
        $current = null;
        $method = null;

        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^paths:[ \t]*(?:#.*)?$/', $line)) {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // 顶层下一个键（components / tags / ...）出现即离开 paths 段
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*:[ \t]*(?:#.*)?$/', $line)) {
                break;
            }
            // 顶层 path：两空格缩进，以 / 开头
            if (preg_match('/^  (\/[^ \t]*):[ \t]*(?:#.*)?$/', $line, $m)) {
                $current = $m[1];
                $byPath[$current] ??= ['methods' => [], 'tags' => []];
                $method = null;
                continue;
            }
            if ($current === null) {
                continue;
            }
            $methodPattern = '/^    (' . implode('|', self::HTTP_METHODS) . '):[ \t]*(?:#.*)?$/i';
            if (preg_match($methodPattern, $line, $m)) {
                $method = strtolower($m[1]);
                $byPath[$current]['methods'][$method] = true;
                continue;
            }
            // operation 级 tags: [认证, 通知]（内联数组，逐行解析，不上 YAML 解析器）
            if ($method !== null && preg_match('/^      tags:[ \t]*\[([^\]]*)\][ \t]*(?:#.*)?$/', $line, $m)) {
                foreach (explode(',', $m[1]) as $tag) {
                    $tag = trim($tag, " \t\r\n\"'");
                    if ($tag !== '') {
                        $byPath[$current]['tags'][$tag] = true;
                    }
                }
            }
        }

        ksort($byPath, SORT_STRING);
        foreach ($byPath as &$info) {
            ksort($info['methods'], SORT_STRING);
            ksort($info['tags'], SORT_STRING);
        }
        unset($info);
        return $byPath;
    }

    /**
     * 端点路径归一化，客户端调用与契约共用同一规则：
     *   1. JS 模板 ${...} 按花括号深度配对；普通表达式（如 ${id}）归一为 {param}，
     *      含 '?' 的三元 / 可选 query 拼接（如 ${deviceId ? '?x=1' : ''}）整体丢弃；
     *   2. 顶层 '?' / '#' 起为 query / fragment，直接截断；
     *   3. 去尾部斜杠差异；
     *   4. 主题相对路径（/auth/login，API_BASE 由 config.js 注入）补 /api 前缀。
     *
     * 这条规则让 \`/api/notifications/${id}/read\` 精确归一为 /api/notifications/{id}/read，
     * 不会被误判成 /api/notifications/ 这类「模板被截断的假漂移」。
     */
    public static function normalizePath(string $raw, bool $relativeToApi = false): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len;) {
            if ($raw[$i] === '$' && $i + 1 < $len && $raw[$i + 1] === '{') {
                $depth = 1;
                $j = $i + 2;
                while ($j < $len && $depth > 0) {
                    if ($raw[$j] === '{') {
                        $depth++;
                    } elseif ($raw[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }
                $expr = substr($raw, $i + 2, $j - $i - 3);
                if (!str_contains($expr, '?')) {
                    $out .= '{param}';
                }
                $i = $j;
                continue;
            }
            if ($raw[$i] === '?' || $raw[$i] === '#') {
                break;
            }
            $out .= $raw[$i];
            $i++;
        }

        $out = preg_replace('/\s+/', '', $out) ?? $out;
        if ($out !== '/') {
            $out = rtrim($out, '/');
        }
        if ($relativeToApi && $out !== '' && $out !== '/api' && !str_starts_with($out, '/api/')) {
            $out = '/api' . $out;
        }
        if ($out === '' || $out[0] !== '/') {
            return null;
        }
        if (!preg_match('#^/[A-Za-z0-9_/{}.\-]*$#', $out)) {
            return null;
        }
        // 占位符名归一：契约写 {id} / {provider}，客户端模板写 ${id}，统一成 {param} 后精确对账。
        $out = preg_replace('/\{[A-Za-z0-9_-]+\}/', '{param}', $out) ?? $out;
        return $out;
    }

    /**
     * 渲染确定性 JSON：无时间戳，sha256 只由 openapi 内容决定，便于逐字节 --check。
     *
     * @param array<string,array{methods:array<string,true>,tags:array<string,true>}> $parsed
     */
    public static function render(array $parsed, string $sourceSha256): string
    {
        $endpoints = [];
        $paths = [];
        foreach ($parsed as $path => $info) {
            $normalized = self::normalizePath((string)$path);
            if ($normalized === null) {
                continue;
            }
            preg_match_all('/\{([A-Za-z0-9_-]+)\}/', (string)$path, $pm);
            $params = array_values(array_unique($pm[1] ?? []));
            sort($params, SORT_STRING);

            $methods = array_keys($info['methods']);
            sort($methods, SORT_STRING);
            $tags = array_keys($info['tags']);
            sort($tags, SORT_STRING);

            $endpoints[] = [
                'path' => (string)$path,
                'normalized' => $normalized,
                'methods' => $methods,
                'path_params' => $params,
                'tags' => $tags,
            ];
            $paths[] = $normalized;
        }
        usort($endpoints, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        $operationCount = 0;
        foreach ($endpoints as $endpoint) {
            $operationCount += count($endpoint['methods']);
        }

        $payload = [
            '//' => '本文件由 scripts/build-client-endpoints.php 自动生成，请勿手工编辑；'
                . '改接口请先改 docs/openapi.yaml 再重新生成，否则 CI 会报「清单过期」。',
            'source' => 'docs/openapi.yaml',
            'source_sha256' => $sourceSha256,
            'path_count' => count($endpoints),
            'operation_count' => $operationCount,
            'paths' => $paths,
            'endpoints' => $endpoints,
        ];
        return (string)json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /** @param array<string,array{methods:array<string,true>,tags:array<string,true>}> $parsed */
    public static function operationCount(array $parsed): int
    {
        $count = 0;
        foreach ($parsed as $info) {
            $count += count($info['methods']);
        }
        return $count;
    }
}

/**
 * CLI 入口（被 require 时只提供类，不产生输出）。
 *
 * @param list<string> $argv
 */
function buildClientEndpointsMain(array $argv): int
{
    $root = dirname(__DIR__);
    $openapiPath = $root . '/docs/openapi.yaml';
    $outPath = $root . '/docs/client_endpoints.json';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--openapi=')) {
            $openapiPath = substr($arg, strlen('--openapi='));
        } elseif (str_starts_with($arg, '--out=')) {
            $outPath = substr($arg, strlen('--out='));
        }
    }
    if (in_array('--help', $argv, true)) {
        echo "用法: php scripts/build-client-endpoints.php [--check|--report] "
            . "[--openapi=path] [--out=path]\n";
        return 0;
    }

    try {
        $yaml = @file_get_contents($openapiPath);
        if ($yaml === false) {
            throw new \RuntimeException("OpenAPI 文件读取失败: {$openapiPath}");
        }
        $sha = (string)hash_file('sha256', $openapiPath);
        $parsed = ClientEndpointManifestBuilder::parseOpenApi($yaml);
        $expected = ClientEndpointManifestBuilder::render($parsed, $sha);
        $pathCount = count($parsed);
        $operationCount = ClientEndpointManifestBuilder::operationCount($parsed);

        if (in_array('--check', $argv, true)) {
            $current = @file_get_contents($outPath);
            if ($current === false) {
                echo "[client-endpoints] 端点清单缺失，请执行: php scripts/build-client-endpoints.php\n";
                return 1;
            }
            // Windows 上 core.autocrlf=true 会把入库的 LF 在 checkout 时转成 CRLF，
            // 逐字节比对会造成「刚刚提交就过期」的假失败，因此只忽略行尾差异。
            $toLf = static fn(string $text): string => str_replace(["\r\n", "\r"], "\n", $text);
            if ($toLf($current) === $toLf($expected)) {
                echo "[client-endpoints] 清单最新（{$pathCount} path / {$operationCount} operation）\n";
                return 0;
            }
            echo "[client-endpoints] 清单过期，请执行: php scripts/build-client-endpoints.php\n";
            return 1;
        }

        if (in_array('--report', $argv, true)) {
            echo "[client-endpoints] openapi path {$pathCount} 个 / operation {$operationCount} 个\n";
            echo "[client-endpoints] 契约源 sha256: {$sha}\n";
            return 0;
        }

        if (file_put_contents($outPath, $expected, LOCK_EX) === false) {
            throw new \RuntimeException("端点清单写入失败: {$outPath}");
        }
        echo "[client-endpoints] 已生成 {$outPath}\n";
        echo "[client-endpoints] path {$pathCount} 个 / operation {$operationCount} 个\n";
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, '[client-endpoints] ' . $e->getMessage() . "\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(buildClientEndpointsMain($argv));
}
