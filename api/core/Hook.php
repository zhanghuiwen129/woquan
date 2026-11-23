<?php
/**
 * Hublog 插件钩子系统
 * 实现 Filter（值过滤）和 Action（事件触发）机制
 * 插件通过挂载钩子介入核心流程，无需改动核心源码
 */

namespace Hublog\Core;

class Hook
{
    private static array $filters = [];
    private static array $actions = [];

    // ==================== Filter（值过滤钩子） ====================

    /**
     * 注册过滤器
     */
    public static function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        self::$filters[$tag][$priority][] = $callback;
    }

    /**
     * 执行过滤器，返回处理后的值
     */
    public static function applyFilter(string $tag, mixed $value, ...$args): mixed
    {
        if (!isset(self::$filters[$tag])) {
            return $value;
        }
        $priorities = array_keys(self::$filters[$tag]);
        sort($priorities);
        foreach ($priorities as $priority) {
            foreach (self::$filters[$tag][$priority] as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }

    /**
     * 检查是否存在指定过滤器
     */
    public static function hasFilter(string $tag): bool
    {
        return isset(self::$filters[$tag]) && !empty(self::$filters[$tag]);
    }

    // ==================== Action（事件钩子） ====================

    /**
     * 注册动作钩子
     */
    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$actions[$tag][$priority][] = $callback;
    }

    /**
     * 触发动作钩子
     */
    public static function doAction(string $tag, ...$args): void
    {
        if (!isset(self::$actions[$tag])) {
            return;
        }
        $priorities = array_keys(self::$actions[$tag]);
        sort($priorities);
        foreach ($priorities as $priority) {
            foreach (self::$actions[$tag][$priority] as $callback) {
                $callback(...$args);
            }
        }
    }

    /**
     * 检查是否存在指定动作
     */
    public static function hasAction(string $tag): bool
    {
        return isset(self::$actions[$tag]) && !empty(self::$actions[$tag]);
    }
}
