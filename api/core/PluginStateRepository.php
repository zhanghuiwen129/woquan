<?php
/**
 * 插件启停 / 安装状态仓储（E2-1 / E2-2）
 *
 * plugin_states 是唯一可信源，Cache('plugin_states', 300s) 只是读缓存；
 * 任何写操作都 invalidateCache()，清缓存后状态仍从 DB 重建。
 *
 * 读路径为 fail-close：迁移尚未执行 / DB 临时不可用时，只放行 CORE_PLUGINS 白名单
 * （5 个无 plugin.json 的核心插件，保证核心功能不因读失败而崩），其余插件一律视为禁用，
 * 避免管理员禁用态被读降级绕过；DB 正常时新插件仍按「无状态行默认启用」处理。
 * 写路径失败则抛出，由控制器给出明确错误，绝不假装写入成功。
 */

declare(strict_types=1);

namespace Hublog\Core;

class PluginStateRepository
{
    public const CACHE_KEY = 'plugin_states';
    private const CACHE_TTL = 300;

    /**
     * 读失败时的最小放行名单：5 个随核心发布、无 plugin.json 的核心插件。
     * DemoExtension 是二开示例而非核心功能，读失败时同样禁用，遵循最保守口径。
     */
    private const CORE_PLUGINS = [
        'aicomment',
        'contentaudit',
        'smartrecommend',
        'smartsearch',
        'smartsummary',
    ];

    /** 本次进程最近一次状态读取是否失败（区分「无状态行」与「读降级」） */
    private static bool $readFailed = false;

    /** 允许写进 plugin_states 的字段白名单，防止控制器拼出任意列 */
    private const WRITABLE_FIELDS = [
        'enabled', 'install_status', 'installed_version', 'last_error', 'installed_at',
    ];

    /**
     * 全量状态表；读取失败时返回空表并置 readFailed 标记，由 isEnabled() 做白名单兜底。
     *
     * @return array<string,array<string,mixed>> 键为小写插件名
     */
    public static function allStates(): array
    {
        self::$readFailed = false;
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
            if (!is_array($states)) {
                // 缓存值损坏也按读失败处理，不能把「读不到」误判成「无状态行 = 默认启用」。
                self::$readFailed = true;
                error_log('[Hublog PluginState] 缓存状态格式异常，仅放行核心插件白名单'); // @allow-error-log
                return [];
            }
            return $states;
        } catch (\Throwable $e) {
            self::$readFailed = true;
            // 读降级必须留痕，Logger 自身在 DB 异常时可能不可用。
            error_log('[Hublog PluginState] 读取状态失败，仅放行核心插件白名单: ' . $e->getMessage()); // @allow-error-log
            return [];
        }
    }

    public static function get(string $name): ?array
    {
        $states = self::allStates();
        return $states[strtolower($name)] ?? null;
    }

    /**
     * 插件是否启用。DB 正常时保持原语义（无状态行默认启用）；
     * 读取异常时只放行 CORE_PLUGINS，其他插件一律禁用（fail-close）。
     */
    public static function isEnabled(string $name): bool
    {
        $state = self::get($name);
        if ($state === null) {
            return self::$readFailed ? self::isCorePlugin($name) : true;
        }
        return (int)($state['enabled'] ?? 1) === 1;
    }

    /** 读降级白名单判定（大小写不敏感，与 allStates() 的键口径一致） */
    private static function isCorePlugin(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::CORE_PLUGINS, true);
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
