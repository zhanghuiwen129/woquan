<?php
/**
 * 插件启停 / 安装状态仓储（E2-1 / E2-2）
 *
 * plugin_states 是唯一可信源，Cache('plugin_states', 300s) 只是读缓存；
 * 任何写操作都 invalidateCache()，清缓存后状态仍从 DB 重建。
 *
 * 读路径刻意「失败降级」：迁移尚未执行 / DB 临时不可用时，isEnabled() 返回 true
 * （新插件默认启用语义），让 5 个内置插件的加载行为与改造前一致；
 * 写路径失败则抛出，由控制器给出明确错误，绝不假装写入成功。
 */

declare(strict_types=1);

namespace Hublog\Core;

class PluginStateRepository
{
    public const CACHE_KEY = 'plugin_states';
    private const CACHE_TTL = 300;

    /** 允许写进 plugin_states 的字段白名单，防止控制器拼出任意列 */
    private const WRITABLE_FIELDS = [
        'enabled', 'install_status', 'installed_version', 'last_error', 'installed_at',
    ];

    /** @return array<string,array<string,mixed>> 键为小写插件名 */
    public static function allStates(): array
    {
        try {
            /** @var array<string,array<string,mixed>> $states */
            $states = Cache::remember(self::CACHE_KEY, static function (): array {
                $result = [];
                foreach (Database::fetchAll('SELECT * FROM `plugin_states`') as $row) {
                    $name = strtolower((string)($row['plugin_name'] ?? ''));
                    if ($name !== '') {
                        $result[$name] = $row;
                    }
                }
                return $result;
            }, self::CACHE_TTL);
            return is_array($states) ? $states : [];
        } catch (\Throwable $e) {
            Logger::error('[Hublog PluginState] 读取状态失败，按默认启用降级: ' . $e->getMessage());
            return [];
        }
    }

    public static function get(string $name): ?array
    {
        $states = self::allStates();
        return $states[strtolower($name)] ?? null;
    }

    /** 新插件（无状态行）默认启用 */
    public static function isEnabled(string $name): bool
    {
        $state = self::get($name);
        if ($state === null) {
            return true;
        }
        return (int)($state['enabled'] ?? 1) === 1;
    }

    /**
     * 写启停状态：存在则 UPDATE，不存在则 INSERT（跨 MySQL/SQLite，不依赖 ON DUPLICATE KEY）。
     */
    public static function setEnabled(string $name, bool $enabled): void
    {
        self::upsert($name, ['enabled' => $enabled ? 1 : 0]);
    }

    /**
     * 增量更新状态行（白名单字段）；首次出现时用默认行补齐后再更新。
     *
     * @param array<string,mixed> $fields
     */
    public static function upsert(string $name, array $fields): void
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('无效的插件名称: ' . $name);
        }

        $patch = [];
        foreach ($fields as $key => $value) {
            if (!in_array((string)$key, self::WRITABLE_FIELDS, true)) {
                throw new \InvalidArgumentException('非法状态字段: ' . $key);
            }
            $patch[(string)$key] = $value;
        }
        if ($patch === []) {
            return;
        }

        try {
            $exists = (int)Database::fetchColumn(
                'SELECT COUNT(*) FROM `plugin_states` WHERE `plugin_name` = ?',
                [$name]
            );
            if ($exists === 0) {
                Database::insert('plugin_states', array_merge([
                    'plugin_name' => $name,
                    'enabled' => 1,
                    'install_status' => 'not_installed',
                    'installed_version' => '',
                    'last_error' => '',
                    'installed_at' => null,
                ], $patch));
            } else {
                Database::update('plugin_states', $patch, '`plugin_name` = ?', [$name]);
            }
            self::invalidateCache();
        } catch (\Throwable $e) {
            Logger::error('[Hublog PluginState] 写入状态失败 ' . $name . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 一次性把 Cache 里的 disabled_plugins 黑名单迁入 plugin_states。
     *
     * 只插入「状态表还不存在对应行」的插件，重复执行返回 0，绝不覆盖管理员后来的 DB 设置；
     * 迁移完成后删除 legacy 缓存键（Cache 只读兼容一个版本，DB 已是唯一可信源）。
     *
     * @return int 本次新插入的禁用行数
     */
    public static function migrateLegacyBlacklist(): int
    {
        try {
            $legacy = Cache::get('disabled_plugins');
            if (!is_array($legacy) || $legacy === []) {
                return 0;
            }
            $inserted = 0;
            foreach ($legacy as $name) {
                if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
                    continue;
                }
                $exists = (int)Database::fetchColumn(
                    'SELECT COUNT(*) FROM `plugin_states` WHERE `plugin_name` = ?',
                    [$name]
                );
                if ($exists === 0) {
                    Database::insert('plugin_states', [
                        'plugin_name' => $name,
                        'enabled' => 0,
                        'install_status' => 'not_installed',
                        'installed_version' => '',
                        'last_error' => '',
                        'installed_at' => null,
                    ]);
                    $inserted++;
                }
            }
            Cache::delete('disabled_plugins');
            self::invalidateCache();
            return $inserted;
        } catch (\Throwable $e) {
            // 升级期 DB 可能还没跑完 migrate101：不阻断加载，下次请求再试。
            Logger::error('[Hublog PluginState] 迁移 disabled_plugins 失败: ' . $e->getMessage());
            return 0;
        }
    }

    private static function invalidateCache(): void
    {
        Cache::delete(self::CACHE_KEY);
    }
}
