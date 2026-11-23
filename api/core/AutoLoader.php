<?php
/**
 * Hublog PSR-4 自动加载器
 */

declare(strict_types=1);

namespace Hublog\Core;

class AutoLoader
{
    private static array $prefixes = [];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass']);
        // PSR-4 映射：命名空间前缀 => 基础目录
        // 注意：目录名必须与文件系统实际大小写一致（Linux 区分大小写，macOS 不区分）
        self::addNamespace('Hublog\\Core\\', __DIR__ . '/');
        self::addNamespace('Hublog\\Controllers\\', __DIR__ . '/../controllers/');
        self::addNamespace('Hublog\\Middleware\\', __DIR__ . '/../middleware/');
    }

    public static function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/') . '/';
        self::$prefixes[$prefix] = $baseDir;
    }

    public static function loadClass(string $class): void
    {
        $prefix = $class;
        while (false !== $pos = strrpos($prefix, '\\')) {
            $prefix = substr($class, 0, $pos + 1);
            $relativeClass = substr($class, $pos + 1);
            
            if (isset(self::$prefixes[$prefix])) {
                $file = self::$prefixes[$prefix] . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
            $prefix = rtrim($prefix, '\\');
        }
    }
}
