<?php
/**
 * 路由清单解析器（G5-1，零外部依赖）
 *
 *   php scripts/parse_routes.php            # 紧凑 JSON（默认同 --json）
 *   php scripts/parse_routes.php --pretty   # 缩进 JSON，便于人工审阅
 *   php scripts/parse_routes.php --source=path/to/routes.php
 *
 * 扫描 api/routes.php 里 $router->get/post/put/delete/patch/group(...) 调用，
 * 用 token_get_all() 取真实 PHP 词法单元（字符串字面量、括号、数组），
 * 还原 group 前缀与中间件，输出规范化清单：
 *   [{"method":"GET","path":"/api/auth/login","middlewares":[...]}, ...]
 *
 * 与 LicenseRouteCoverageTest::parseRoutes() 同口径（同一份 routes.php、同一组方法），
 * 但额外解析中间件，并把解析能力抽成 Hublog\Docs\RouteParser 供对账脚本与单测复用。
 * 只做静态分析：不加载路由文件、不实例化 Router、不执行任何回调。
 */

declare(strict_types=1);

namespace Hublog\Docs;

final class RouteParser
{
    /** 参与解析的路由方法 */
    private const HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch'];

    /**
     * 解析路由文件。
     *
     * @return list<array{method:string,path:string,middlewares:list<string>}>
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("路由文件不存在: {$path}");
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("路由文件读取失败: {$path}");
        }
        return self::parse($source);
    }

    /**
     * 解析路由源码字符串。
     *
     * @return list<array{method:string,path:string,middlewares:list<string>}>
     */
    public static function parse(string $source): array
    {
        $tokens = self::significantTokens($source);
        $total = count($tokens);
        $routes = [];
        /** @var list<array{prefix:string,middlewares:list<string>,end:int}> $stack */
        $stack = [];

        for ($i = 0; $i < $total; $i++) {
            // group 的闭包已经扫描完 → 弹出该层前缀与中间件
            while ($stack !== [] && $i > $stack[count($stack) - 1]['end']) {
                array_pop($stack);
            }

            if (($tokens[$i]['text'] ?? '') !== '$router') {
                continue;
            }
            if (($tokens[$i + 1]['text'] ?? '') !== '->') {
                continue;
            }
            $method = strtolower((string)($tokens[$i + 2]['text'] ?? ''));
            if (!in_array($method, [...self::HTTP_METHODS, 'group'], true)) {
                continue;
            }
            if (($tokens[$i + 3]['text'] ?? '') !== '(') {
                continue;
            }

            [$args, $close] = self::readArguments($tokens, $i + 3);

            if ($method === 'group') {
                $prefix = self::stringArgument($args[0] ?? []);
                if ($prefix === '') {
                    continue;
                }
                // 闭包体仍在参数里，压栈后继续顺序扫描，不跳过内部路由。
                $openBrace = null;
                for ($k = $i + 4; $k < $close; $k++) {
                    if ($tokens[$k]['text'] === '{') {
                        $openBrace = $k;
                        break;
                    }
                }
                if ($openBrace === null) {
                    continue;
                }
                $stack[] = [
                    'prefix' => rtrim($prefix, '/'),
                    'middlewares' => self::stringListArgument($args[2] ?? []),
                    'end' => self::matchingBrace($tokens, $openBrace),
                ];
                continue;
            }

            $path = self::stringArgument($args[0] ?? []);
            if ($path === '') {
                continue;
            }

            $prefixes = [];
            $groupMiddlewares = [];
            foreach ($stack as $frame) {
                $prefixes[] = $frame['prefix'];
                foreach ($frame['middlewares'] as $middleware) {
                    $groupMiddlewares[] = $middleware;
                }
            }
            $prefixes[] = $path;
            $routes[] = [
                'method' => strtoupper($method),
                'path' => self::joinPath($prefixes),
                'middlewares' => array_values(array_unique(array_merge(
                    $groupMiddlewares,
                    self::stringListArgument($args[2] ?? [])
                ))),
            ];

            // 路由参数里不会再出现路由注册，直接跳到 ) 减少无效扫描。
            $i = $close;
        }

        return $routes;
    }

    /**
     * 去掉空白与注释，保留原始词法单元文本。
     *
     * @return list<array{type:int|string,text:string}>
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $tokens[] = ['type' => $token[0], 'text' => $token[1]];
                continue;
            }
            $tokens[] = ['type' => $token, 'text' => $token];
        }
        return $tokens;
    }

    /**
     * 从开括号位置读出参数列表，按顶层逗号切分。
     * 数组内的逗号、闭包体内的逗号都不算参数分隔符。
     *
     * @param list<array{type:int|string,text:string}> $tokens
     * @return array{0:list<list<array{type:int|string,text:string}>>,1:int} 参数列表与右括号下标
     */
    private static function readArguments(array $tokens, int $openIndex): array
    {
        $arguments = [[]];
        $paren = 0;
        $bracket = 0;
        $brace = 0;
        $total = count($tokens);

        for ($i = $openIndex; $i < $total; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(') {
                $paren++;
                if ($paren === 1) {
                    continue;
                }
            } elseif ($text === ')') {
                $paren--;
                if ($paren === 0) {
                    return [$arguments, $i];
                }
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket--;
            } elseif ($text === '{') {
                $brace++;
            } elseif ($text === '}') {
                $brace--;
            } elseif ($text === ',' && $paren === 1 && $bracket === 0 && $brace === 0) {
                $arguments[] = [];
                continue;
            }
            $arguments[count($arguments) - 1][] = $tokens[$i];
        }

        return [$arguments, $total - 1];
    }

    /**
     * 取单个字符串字面量参数（路径 / group 前缀）。
     *
     * @param list<array{type:int|string,text:string}> $tokens
     */
    private static function stringArgument(array $tokens): string
    {
        if (count($tokens) !== 1) {
            return '';
        }
        $token = $tokens[0];
        if ($token['type'] !== T_CONSTANT_ENCAPSED_STRING) {
            return '';
        }
        $raw = (string)$token['text'];
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        $body = substr($raw, 1, max(0, strlen($raw) - 2));
        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }

    /**
     * 取字符串数组参数（中间件列表），支持 [ 'A', 'B' ] 与 array('A', 'B')。
     *
     * @param list<array{type:int|string,text:string}> $tokens
     * @return list<string>
     */
    private static function stringListArgument(array $tokens): array
    {
        $first = $tokens[0]['text'] ?? '';
        $last = $tokens[count($tokens) - 1]['text'] ?? '';
        if ($first === '[' && $last === ']') {
            $tokens = array_slice($tokens, 1, -1);
        } elseif ($first === 'array' && ($tokens[1]['text'] ?? '') === '(' && $last === ')') {
            $tokens = array_slice($tokens, 2, -1);
        } else {
            return [];
        }

        $result = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token['text'] === '[' || $token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ']' || $token['text'] === ')') {
                $depth--;
            } elseif ($token['text'] === ',' && $depth === 0) {
                $value = self::stringArgument($current);
                if ($value !== '') {
                    $result[] = $value;
                }
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        $value = self::stringArgument($current);
        if ($value !== '') {
            $result[] = $value;
        }
        return $result;
    }

    /**
     * 找到与 $openIndex 处 { 配对的 } 下标。
     *
     * @param list<array{type:int|string,text:string}> $tokens
     */
    private static function matchingBrace(array $tokens, int $openIndex): int
    {
        $depth = 0;
        $total = count($tokens);
        for ($i = $openIndex; $i < $total; $i++) {
            if ($tokens[$i]['text'] === '{') {
                $depth++;
            } elseif ($tokens[$i]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return $total - 1;
    }

    /**
     * 拼接 group 前缀与局部路径，统一为单个前导斜杠、无重复斜杠。
     *
     * @param list<string> $segments
     */
    private static function joinPath(array $segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $part = trim((string)$segment, '/');
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return '/' . implode('/', $parts);
    }
}

/**
 * CLI 入口：输出规范化路由清单 JSON。
 *
 * @param list<string> $argv
 */
function parseRoutesMain(array $argv): int
{
    $source = dirname(__DIR__) . '/api/routes.php';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--source=')) {
            $source = substr($arg, strlen('--source='));
        }
    }
    if (in_array('--help', $argv, true)) {
        echo "用法: php scripts/parse_routes.php [--json] [--pretty] [--source=path]\n";
        return 0;
    }

    try {
        $routes = RouteParser::parseFile($source);
    } catch (\Throwable $e) {
        fwrite(STDERR, '[parse-routes] ' . $e->getMessage() . "\n");
        return 2;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (in_array('--pretty', $argv, true)) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo (string)json_encode($routes, $flags), PHP_EOL;
    return 0;
}

// 被 require 时只提供类，不产生任何输出；直接运行时才走 CLI。
if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    exit(parseRoutesMain($argv));
}
