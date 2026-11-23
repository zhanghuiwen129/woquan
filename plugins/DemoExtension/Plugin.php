<?php
/**
 * 演示扩展（DemoExtension）—— 最小二开示例插件
 *
 * 只演示机制，不含任何垂直业务逻辑（课程 / 电商 / 直播等一律不进核心，C1）：
 *   1. plugin.json 声明 plugin_demo_notes 表，由核心生成 DDL，本文件不拼任何裸 SQL/DDL；
 *   2. install() / upgrade($from,$to) / uninstall() 只做数据初始化与日志，均为可选钩子；
 *   3. init() 挂 post_created 写一条演示记录，签名与 5 个内置插件口径一致；
 *   4. registerAdmin() 只调只读上下文的 addMenu()/registerSettings()，不触碰核心状态。
 *
 * @name 演示扩展
 * @description 演示清单建表、设置页配置与业务钩子机制的最小二开示例，不含垂直业务。
 * @version 1.0.0
 * @author woquan
 */

declare(strict_types=1);

namespace Hublog\Plugin\DemoExtension;

use Hublog\Core\Database;
use Hublog\Core\Hook;
use Hublog\Core\Logger;
use Hublog\Core\PluginAdminContext;
use Hublog\Core\PluginConfigRepository;

class Plugin
{
    /** 设置页开关对应的插件局部键（snake_case，落 plugin_configs，不进核心 settings 白名单） */
    private const CONFIG_POST_HOOK_ENABLED = 'post_hook_enabled';

    private const PLUGIN_NAME = 'DemoExtension';

    private const NOTE_TABLE = 'plugin_demo_notes';

    /**
     * 插件入口：仅在 api/index.php 常规加载路径调用，后台受控加载不触发本方法。
     */
    public function init(): void
    {
        // 优先级 30：排在审核(5)/摘要(15)/搜索(15)/评论后、AI 评论(20) 之后。
        Hook::addAction('post_created', [self::class, 'onPostCreated'], 30);
    }

    /**
     * post_created 钩子回调：写一条演示记录到清单声明的表。
     *
     * 参数个数/顺序严格对齐 §5.1 与现有 5 个插件口径；异常在插件内自吞，
     * 绝不因演示逻辑影响发帖主流程。
     */
    public static function onPostCreated(int $postId, int $userId): void
    {
        try {
            // 未配置视为开启：设置页只演示「可配置」，不默认改变发帖行为。
            $enabled = PluginConfigRepository::get(self::PLUGIN_NAME, self::CONFIG_POST_HOOK_ENABLED);
            if ($enabled === '0') {
                return;
            }
            Database::insert(self::NOTE_TABLE, [
                'post_id'    => $postId,
                'user_id'    => $userId,
                'note'       => 'demo post_created post=' . $postId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Logger::info('[DemoExtension] post_created 已记录演示条目', [
                'post_id' => $postId,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            Logger::error('[DemoExtension] post_created 钩子执行失败: ' . $e->getMessage());
        }
    }

    /**
     * 安装钩子：只做数据初始化；建表由 plugin.json 的 required_tables 声明、核心生成 DDL。
     */
    public function install(): void
    {
        if (PluginConfigRepository::get(self::PLUGIN_NAME, self::CONFIG_POST_HOOK_ENABLED) === null) {
            PluginConfigRepository::set(self::PLUGIN_NAME, self::CONFIG_POST_HOOK_ENABLED, '1');
        }
        Logger::info('[DemoExtension] 安装完成：演示配置已初始化，表结构由清单声明');
    }

    /**
     * 升级钩子：演示跨版本调用，真实升级逻辑由插件自行编写。
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        Logger::info('[DemoExtension] 升级完成', [
            'from' => $fromVersion,
            'to'   => $toVersion,
        ]);
    }

    /**
     * 卸载钩子：默认保留数据表（drop_tables_on_uninstall=false），不在这里删表。
     */
    public function uninstall(): void
    {
        Logger::info('[DemoExtension] 已停用，演示数据默认保留');
    }

    /**
     * 后台受控加载入口（Q8）：只收集菜单与设置声明，不注册任何业务钩子。
     *
     * 清单声明已由核心注入上下文，这里重复登记用于演示代码侧的注册方式
     * （同 page / 同 plugin 由 PluginAdminContext 去重，清单优先）。
     */
    public function registerAdmin(PluginAdminContext $ctx): void
    {
        $ctx->addMenu([
            'group' => 'content',
            'title' => '演示笔记',
            'icon'  => 'note',
            'page'  => 'plugin-demo-notes',
            'sort'  => 100,
        ]);
        $ctx->registerSettings([
            'page'         => 'settings.html',
            'config_scope' => 'demo',
            'title'        => '演示扩展设置',
        ]);
    }
}
