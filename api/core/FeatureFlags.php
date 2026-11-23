<?php
declare(strict_types=1);
/**
 * 站点模式 · 运行期读取入口
 *
 * 唯一解析出口：module_flags 只在 ModuleCatalog::normalize() 里解一次，
 * 后台菜单、/site/config、/app/config、FeatureGuard、cron 全部复用，杜绝多处解析漂移。
 */

namespace Hublog\Core;

final class FeatureFlags
{
    /** @return array<string,int> 全 15 个开关，缺省为 1 */
    public static function all(): array
    {
        return ModuleCatalog::normalize(SettingsRepository::get('module_flags'));
    }

    /** 未知 key 一律放行（fail-open），新模块未注册时不会被误关 */
    public static function enabled(string $key): bool
    {
        $flags = self::all();
        return !isset($flags[$key]) || $flags[$key] === 1;
    }

    /** 仅用于界面回显，不参与任何逻辑判定 */
    public static function mode(): string
    {
        $raw = SettingsRepository::get('site_mode');
        if (is_string($raw) && in_array($raw, ModuleCatalog::MODES, true)) return $raw;
        return ModuleCatalog::detectMode(self::all());
    }

    /** 运行层拦截灰度总闸：off(默认) / log / on */
    public static function guardMode(): string
    {
        $raw = SettingsRepository::get('feature_guard_mode');
        return is_string($raw) && in_array($raw, ModuleCatalog::GUARD_MODES, true) ? $raw : 'off';
    }

    /**
     * 下发给前台 / App / 后台菜单的公开载荷。
     * 旧客户端不认识这些字段时按「缺失即全显示」处理，无需强更。
     */
    public static function publicPayload(): array
    {
        $flags = self::all();
        $enabled = [];
        foreach ($flags as $key => $on) {
            if ($on === 1) $enabled[] = $key;
        }
        return [
            'enabled_modules' => $enabled,
            'module_pages'    => ModuleCatalog::pageMap($flags),
            'site_mode'       => self::mode(),
        ];
    }
}
