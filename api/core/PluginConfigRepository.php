<?php
/**
 * 插件配置仓储（E2-4 / Q4）
 *
 * plugin_configs 以 (plugin_name, config_key) 作作用域隔离，核心 settings 白名单不新增任何插件键。
 * 依赖 V101 迁移创建的 uk_plugin_config 唯一索引实现幂等 upsert。
 *
 * 【安全红线】T02 的 PluginSchemaMigrator 用 plugin_configs._schema_tables 记录
 * 「跨插件同名表归属」，后安装者据此拒绝建表。若插件能通过本仓储写这个键，
 * 就能伪造/清除归属绕过拦截，所以这里在 get/set/delete 三个入口硬编码黑名单：
 * 保留键一律拒绝（403），且读取/列举时不回显给插件。
 */

declare(strict_types=1);

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class PluginConfigRepository
{
    /** 核心内部键，禁止插件经公共配置 API 读写 */
    private const RESERVED_KEYS = ['_schema_tables' => true];

    /**
     * 读取单个配置值；不存在返回 null。
     *
     * @throws HublogException 400 参数非法 / 403 保留键
     */
    public static function get(string $plugin, string $key): ?string
    {
        self::assertPluginName($plugin);
        self::assertKeyAllowed($key, false);
        $row = Database::fetchOne(
            'SELECT `config_value` FROM `plugin_configs` WHERE `plugin_name` = ? AND `config_key` = ? LIMIT 1',
            [$plugin, $key]
        );
        return $row === null ? null : (string)($row['config_value'] ?? '');
    }

    /**
     * 幂等写入（uk_plugin_config）：同插件同键重复写只更新值，不新增行。
     *
     * @throws HublogException 400 参数非法 / 403 保留键
     */
    public static function set(string $plugin, string $key, string $value): bool
    {
        self::assertPluginName($plugin);
        self::assertKeyAllowed($key, true);
        $existing = Database::fetchOne(
            'SELECT `id` FROM `plugin_configs` WHERE `plugin_name` = ? AND `config_key` = ? LIMIT 1',
            [$plugin, $key]
        );
        if ($existing === null) {
            try {
                Database::insert('plugin_configs', [
                    'plugin_name'  => $plugin,
                    'config_key'   => $key,
                    'config_value' => $value,
                ]);
            } catch (\Throwable $e) {
                // 并发下另一请求先插入：唯一键冲突后转成更新，保证最终幂等。
                // 先回查确认确实是「键已存在」，其它数据库错误原样抛出，不吞故障。
                $raced = Database::fetchOne(
                    'SELECT `id` FROM `plugin_configs` WHERE `plugin_name` = ? AND `config_key` = ? LIMIT 1',
                    [$plugin, $key]
                );
                if ($raced === null) {
                    throw $e;
                }
                Database::update(
                    'plugin_configs',
                    ['config_value' => $value],
                    '`plugin_name` = ? AND `config_key` = ?',
                    [$plugin, $key]
                );
            }
            return true;
        }
        Database::update(
            'plugin_configs',
            ['config_value' => $value],
            '`plugin_name` = ? AND `config_key` = ?',
            [$plugin, $key]
        );
        return true;
    }

    /**
     * 列举某插件的全部配置（key => value），不返回核心保留键。
     *
     * @return array<string,string>
     * @throws HublogException 400 参数非法
     */
    public static function all(string $plugin): array
    {
        self::assertPluginName($plugin);
        $rows = Database::fetchAll(
            'SELECT `config_key`, `config_value` FROM `plugin_configs` WHERE `plugin_name` = ?',
            [$plugin]
        );
        $result = [];
        foreach ($rows as $row) {
            $key = (string)($row['config_key'] ?? '');
            if (self::isReservedKey($key)) {
                continue;
            }
            $result[$key] = (string)($row['config_value'] ?? '');
        }
        return $result;
    }

    /**
     * 删除单个配置；保留键同样禁止删除（否则可清掉表归属登记）。
     *
     * @throws HublogException 400 参数非法 / 403 保留键
     */
    public static function delete(string $plugin, string $key): bool
    {
        self::assertPluginName($plugin);
        self::assertKeyAllowed($key, true);
        Database::delete(
            'plugin_configs',
            '`plugin_name` = ? AND `config_key` = ?',
            [$plugin, $key]
        );
        return true;
    }

    /** 供控制器 / 测试显式判断核心保留键 */
    public static function isReservedKey(string $key): bool
    {
        return isset(self::RESERVED_KEYS[strtolower($key)]);
    }

    /** 插件目录名白名单，与 PluginManifest 的 name 规则一致 */
    private static function assertPluginName(string $plugin): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $plugin) !== 1) {
            throw new HublogException('无效的插件名称', 400);
        }
    }

    /** 配置键 snake_case；保留键在写路径上硬拒绝 */
    private static function assertKeyAllowed(string $key, bool $writable): void
    {
        // 保留键先判：它以下划线开头，若先跑 snake_case 校验会得到语义错误的 400
        if (self::isReservedKey($key)) {
            throw new HublogException(
                $writable
                    ? '配置键 _schema_tables 为核心保留键，插件不可写入'
                    : '配置键 _schema_tables 为核心保留键，插件不可读取',
                403
            );
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,127}$/', $key) !== 1) {
            throw new HublogException('配置键必须是 snake_case 格式', 400);
        }
    }
}
