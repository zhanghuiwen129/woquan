<?php
/**
 * Hublog - 零依赖轻量级 .env 配置加载器
 * 
 * 特性：
 *   - 无 Composer 依赖，原生 PHP 解析
 *   - 支持 ../.env（父目录，最安全）优先，fallback 到根目录 .env
 *   - 支持 # 注释、引号包裹值、= 号两侧空格
 *   - 支持 getenv() / $_ENV / $_SERVER 三栈同步
 *   - 支持 BOOLEAN / NULL / INTEGER 类型转换
 *   - 只读一次，重复调用返回缓存（静态变量）
 *   - 找不到 .env 文件时返回空数组，不抛错（由上层 fallback）
 *
 * 兼容：PHP 7.4+ / 8.x
 * 后续 P1-A1 可平滑替换为 vlucas/phpdotenv，调用接口不变
 */

declare(strict_types=1);

namespace Hublog\Core;

class EnvLoader
{
    /** @var array<string, string>|null 解析后缓存 */
    private static ?array $cachedEnv = null;

    /** @var bool 是否已执行过 load() */
    private static bool $loaded = false;

    /**
     * 按优先级顺序尝试定位 .env 文件并加载
     *
     * @param string $projectRoot 项目根目录（通常 = __DIR__ . '/../..'）
     * @param bool   $override    是否覆盖已存在的 $_ENV 值（默认 false，尊重系统环境变量）
     * @return array<string, scalar> 解析出的键值对（类型转换后）
     */
    public static function load(string $projectRoot, bool $override = false): array
    {
        if (self::$loaded && !$override) {
            return self::$cachedEnv ?? [];
        }

        // 优先级：1) 项目父目录 ../.env  2) 项目根目录 .env
        $candidates = [
            dirname($projectRoot) . DIRECTORY_SEPARATOR . '.env', // 父目录：最安全，Web 绝对访问不到
            $projectRoot . DIRECTORY_SEPARATOR . '.env',          // 项目根：兼容共享主机
        ];

        $envPath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $envPath = $candidate;
                break;
            }
        }

        $parsed = [];
        if ($envPath !== null) {
            $parsed = self::parseFile($envPath);
            self::populateEnv($parsed, $override);
        }

        self::$cachedEnv = $parsed;
        self::$loaded    = true;

        return $parsed;
    }

    /**
     * 读取单个 env 变量（优先系统 getenv，再 fallback 到 .env 缓存）
     *
     * @template TDefault
     * @param string          $key
     * @param TDefault        $default
     * @return mixed|TDefault
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // 1) 系统环境变量（Docker / CI / 服务器管理员设置）
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return self::castValue($value);
        }

        // 2) $_ENV（php.ini: variables_order=EGPCS 时存在）
        if (array_key_exists($key, $_ENV)) {
            return self::castValue((string) $_ENV[$key]);
        }

        // 3) .env 本地缓存
        if (self::$cachedEnv !== null && array_key_exists($key, self::$cachedEnv)) {
            return self::$cachedEnv[$key];
        }

        return $default;
    }

    /**
     * 判断 .env 是否被成功加载（用于提示用户生成配置）
     */
    public static function isLoaded(): bool
    {
        return self::$cachedEnv !== null && self::$cachedEnv !== [];
    }

    /**
     * 释放静态缓存（仅用于单元测试）
     * @codeCoverageIgnore
     */
    public static function reset(): void
    {
        self::$cachedEnv = null;
        self::$loaded    = false;
    }

    // ─────────────────────── 内部实现 ───────────────────────

    /**
     * 逐行解析 .env 文件
     */
    private static function parseFile(string $path): array
    {
        $lines  = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $result = [];

        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            // 跳过空行 / 注释行
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // 跳过非法行（无 = 号）
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // 键名必须是合法标识符
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            // 去除首尾引号（单/双）
            if (self::isQuoted($value)) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    // 双引号：解析转义字符 \n \r \t \\ \"
                    $value = str_replace(
                        ['\\n', '\\r', '\\t', '\\\\', '\\"'],
                        ["\n",   "\r",   "\t",   '\\',   '"'],
                        $value
                    );
                }
            } else {
                // 无引号：去掉行尾 # 注释
                $hashPos = strpos($value, ' #');
                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
                $value = trim($value);
            }

            $result[$key] = self::castValue($value);
        }

        return $result;
    }

    /**
     * 同步到 getenv() / $_ENV / $_SERVER
     */
    private static function populateEnv(array $parsed, bool $override): void
    {
        foreach ($parsed as $key => $value) {
            $scalarValue = is_scalar($value) ? $value : var_export($value, true);
            $stringValue = (string) $scalarValue;

            // 不覆盖系统环境变量（除非 override=true）
            if (!$override && getenv($key) !== false) {
                continue;
            }

            putenv(sprintf('%s=%s', $key, $stringValue));
            $_ENV[$key]    = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }

    /**
     * 字符串 → PHP 标量类型（bool/null/int/float/string）
     */
    private static function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        // 布尔
        if ($lower === 'true'  || $lower === '(true)')  { return true;  }
        if ($lower === 'false' || $lower === '(false)') { return false; }
        if ($lower === 'null'  || $lower === '(null)')  { return null;  }
        if ($lower === 'empty' || $lower === '(empty)') { return '';    }

        // 整数
        if (preg_match('/^-?\d+$/', $value)) {
            $intVal = (int) $value;
            if (var_export($intVal, true) === $value) {
                return $intVal;
            }
        }

        // 浮点数
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    private static function isQuoted(string $value): bool
    {
        $len = strlen($value);
        if ($len < 2) {
            return false;
        }
        return ($value[0] === '"' && $value[$len - 1] === '"')
            || ($value[0] === "'" && $value[$len - 1] === "'");
    }
}
