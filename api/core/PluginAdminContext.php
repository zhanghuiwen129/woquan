<?php
/**
 * 后台受控加载上下文（E2-3 / Q8）
 *
 * 只在 Plugin::loadAdminScoped() 的后台作用域里构造，交给插件的 registerAdmin() 收集
 * 菜单与设置声明。该对象刻意不持有 Database / Cache / Settings / Router 等核心能力，
 * 只暴露 addMenu()/registerSettings() 两个收集方法与 menus()/settings() 两个只读读取口，
 * 从类型层面杜绝插件在后台渲染阶段改写核心状态。
 *
 * 清单（plugin.json）里的声明会作为初始项注入，未实现 registerAdmin() 的插件同样生效；
 * 插件可在 registerAdmin() 里按需追加。所有 spec 在写入前按 PluginManifest 的同一套规则
 * 重新校验一遍，避免运行时拼接出越界 group / page。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class PluginAdminContext
{
    /** 与 PluginManifest::checkMenuSpec() 保持一致的展示分组白名单 */
    private const MENU_GROUPS = ['overview', 'content', 'user', 'safety', 'commerce', 'system'];

    private string $pluginName;
    /** @var list<array<string,mixed>> */
    private array $menus = [];
    /** @var list<array<string,mixed>> */
    private array $settings = [];

    /**
     * @param string $pluginName 插件目录名（写入声明作为归属标识）
     * @param list<mixed> $manifestMenus plugin.json 的 admin_menu
     * @param array<string,mixed>|null $manifestSettings plugin.json 的 admin_settings
     */
    public function __construct(string $pluginName, array $manifestMenus = [], ?array $manifestSettings = null)
    {
        $this->pluginName = $pluginName;
        foreach ($manifestMenus as $spec) {
            if (is_array($spec)) {
                $this->addMenu($spec);
            }
        }
        if (is_array($manifestSettings)) {
            $this->registerSettings($manifestSettings);
        }
    }

    /**
     * 追加一条后台菜单声明。非法 spec 只记录日志并丢弃，不影响其它菜单。
     *
     * @param array<string,mixed> $spec group/title/icon/page/sort
     */
    public function addMenu(array $spec): void
    {
        $group = isset($spec['group']) ? (string)$spec['group'] : '';
        $page = isset($spec['page']) ? (string)$spec['page'] : '';
        $title = isset($spec['title']) ? (string)$spec['title'] : '';
        $icon = isset($spec['icon']) ? (string)$spec['icon'] : '';

        if (!in_array($group, self::MENU_GROUPS, true)
            || preg_match('/^plugin-[a-zA-Z][a-zA-Z0-9-]{0,47}$/', $page) !== 1
            || $title === '' || strlen($title) > 32 || strlen($icon) > 32
        ) {
            Logger::error('[Hublog PluginAdminContext] 插件 ' . $this->pluginName . ' 的菜单声明非法，已忽略');
            return;
        }
        foreach ($this->menus as $existing) {
            if (($existing['page'] ?? '') === $page) {
                return; // 同一 page 只保留首次声明（清单优先）
            }
        }

        $safe = [
            'group'  => $group,
            'title'  => $title,
            'icon'   => $icon,
            'page'   => $page,
            'plugin' => $this->pluginName,
        ];
        if (isset($spec['sort']) && is_int($spec['sort'])) {
            $safe['sort'] = $spec['sort'];
        }
        $this->menus[] = $safe;
    }

    /**
     * 登记插件的设置页声明。每个插件只允许一份，后调用者覆盖前调用者。
     *
     * @param array<string,mixed> $spec page/config_scope/title
     */
    public function registerSettings(array $spec): void
    {
        $page = isset($spec['page']) ? (string)$spec['page'] : '';
        $scope = isset($spec['config_scope']) ? (string)$spec['config_scope'] : '';
        $title = isset($spec['title']) ? (string)$spec['title'] : '';

        if ($page !== 'settings.html'
            || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/', $scope) !== 1
            || strlen($title) > 32
        ) {
            Logger::error('[Hublog PluginAdminContext] 插件 ' . $this->pluginName . ' 的设置页声明非法，已忽略');
            return;
        }
        $entry = [
            'plugin_name'  => $this->pluginName,
            'page'         => $page,
            'config_scope' => $scope,
            'title'        => $title !== '' ? $title : $this->pluginName,
        ];
        foreach ($this->settings as $i => $existing) {
            if (($existing['plugin_name'] ?? '') === $this->pluginName) {
                $this->settings[$i] = $entry;
                return;
            }
        }
        $this->settings[] = $entry;
    }

    /** @return list<array<string,mixed>> */
    public function menus(): array
    {
        return $this->menus;
    }

    /** @return list<array<string,mixed>> */
    public function settings(): array
    {
        return $this->settings;
    }
}
